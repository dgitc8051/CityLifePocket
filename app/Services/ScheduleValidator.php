<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAvailability;
use App\Models\LeaveRequest;
use App\Models\ScheduleEntry;
use App\Models\ShiftTemplate;
use App\Models\Shop;
use Carbon\CarbonImmutable;

class ScheduleValidator
{
    /**
     * @return array{
     *   errors: array<array{level: string, msg: string}>,
     *   warnings: array<array{level: string, msg: string}>
     * }
     */
    public function validateEntry(int $employeeId, int $shiftTemplateId, string $date): array
    {
        $errors = [];
        $warnings = [];

        $employee = Employee::find($employeeId);
        $shift = ShiftTemplate::find($shiftTemplateId);

        if (! $employee || ! $shift) {
            $errors[] = ['level' => 'block', 'msg' => '員工或時段不存在'];

            return ['errors' => $errors, 'warnings' => $warnings];
        }

        // Hard: 員工已離職
        if ($employee->status === 'terminated') {
            $errors[] = ['level' => 'block', 'msg' => '此員工已離職'];
        }

        $entryDate = CarbonImmutable::parse($date);

        // Hard: 該員工該日該時段是否已排
        $exists = ScheduleEntry::where('employee_id', $employeeId)
            ->where('shift_template_id', $shiftTemplateId)
            ->where('date', $date)
            ->exists();
        if ($exists) {
            $errors[] = ['level' => 'block', 'msg' => '此員工已被排入此時段'];
        }

        // Hard: 該員工該日其他時段是否時間重疊
        $sameDayEntries = ScheduleEntry::where('employee_id', $employeeId)
            ->where('date', $date)
            ->where('shift_template_id', '!=', $shiftTemplateId)
            ->with('shiftTemplate:id,name,start_time,end_time')
            ->get();
        if ($sameDayEntries->isNotEmpty()) {
            [$newStart, $newEnd] = $this->minutesRange($shift->start_time, $shift->end_time);
            foreach ($sameDayEntries as $existing) {
                if (! $existing->shiftTemplate) continue;
                [$exStart, $exEnd] = $this->minutesRange($existing->shiftTemplate->start_time, $existing->shiftTemplate->end_time);
                if ($this->rangesOverlap($newStart, $newEnd, $exStart, $exEnd)) {
                    $errors[] = [
                        'level' => 'block',
                        'msg' => "時段重疊：當日已排「{$existing->shiftTemplate->name}」".substr($existing->shiftTemplate->start_time, 0, 5).'–'.substr($existing->shiftTemplate->end_time, 0, 5),
                    ];
                    break;
                }
            }
        }

        // Hard: 請假衝突
        $hasLeave = LeaveRequest::where('employee_id', $employeeId)
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('start_datetime', '<=', $date)
            ->whereDate('end_datetime', '>=', $date)
            ->exists();
        if ($hasLeave) {
            $errors[] = ['level' => 'block', 'msg' => '該員工該日已有請假申請'];
        }

        // 規則由店家設定，沒設定時用勞基法預設
        $rules = $employee->shop?->settings_json ?? [];
        $maxConsecutive = (int) ($rules['max_consecutive_work_days'] ?? 6);

        // Soft: 連續上班
        $consecutive = $this->countConsecutiveDays($employeeId, $entryDate);
        if ($consecutive > $maxConsecutive) {
            $warnings[] = ['level' => 'warn', 'msg' => "已連續上班 {$consecutive} 天（上限 {$maxConsecutive}）"];
        }

        // Soft: 月度上限
        $employmentType = $employee->employment_type;
        $maxKey = "{$employmentType}_max_days_per_month";
        $minKey = "{$employmentType}_min_days_per_month";
        $monthMax = isset($rules[$maxKey]) ? (int) $rules[$maxKey] : null;
        $monthMin = isset($rules[$minKey]) ? (int) $rules[$minKey] : null;

        if ($monthMax || $monthMin) {
            $monthStart = $entryDate->startOfMonth()->toDateString();
            $monthEnd = $entryDate->endOfMonth()->toDateString();
            $monthDays = ScheduleEntry::where('employee_id', $employeeId)
                ->whereBetween('date', [$monthStart, $monthEnd])
                ->distinct('date')
                ->count('date');

            if ($monthMax && $monthDays >= $monthMax) {
                $warnings[] = ['level' => 'warn', 'msg' => "員工本月已排 {$monthDays} 天，超過上限 {$monthMax}"];
            }
        }

        // 員工填的可上時段（檢查兩個 case）
        $weekStart = $entryDate->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $availability = EmployeeAvailability::where([
            'employee_id' => $employeeId,
            'week_start_date' => $weekStart,
            'day_of_week' => $entryDate->dayOfWeek,
            'shift_template_id' => $shiftTemplateId,
        ])->first();

        $hasAnySubmission = EmployeeAvailability::where('employee_id', $employeeId)
            ->where('week_start_date', $weekStart)
            ->exists();

        // Hard #1: 明確標 unavailable
        if ($availability && $availability->availability === 'unavailable') {
            $errors[] = ['level' => 'block', 'msg' => '員工已標示此時段不可上班'];
        }

        // Hard #2: 員工該週有提交過 availability、但這個時段沒填 → 視為不願意
        // （擋住「店家新增時段，員工沒重填，店長照排」的情況）
        if (! $availability && $hasAnySubmission) {
            $errors[] = ['level' => 'block', 'msg' => '員工該週的可上時段沒包含此時段（請員工重填或店長代填）'];
        }

        // Info: 員工尚未填寫此週可上時段
        if (! $hasAnySubmission) {
            $warnings[] = ['level' => 'info', 'msg' => '員工尚未填寫此週可上時段'];
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * 算 entry 在當天的時段，整個 slot 的達標狀態
     */
    public function validateSlot(int $shopId, int $shiftTemplateId, string $date): array
    {
        $shift = ShiftTemplate::find($shiftTemplateId);
        if (! $shift) {
            return ['warnings' => [['level' => 'block', 'msg' => '時段不存在']]];
        }

        $entries = ScheduleEntry::where('shift_template_id', $shiftTemplateId)
            ->where('date', $date)
            ->with(['employee:id,name,level,skill_score,status', 'employee.stations:id,name'])
            ->get();

        $totalScore = $entries->sum(fn ($e) => $e->employee?->skill_score ?? 0);
        $seniorCount = $entries
            ->filter(fn ($e) => in_array($e->employee?->level, ['senior', 'lead'], true))
            ->count();
        $headcount = $entries->count();

        $warnings = [];

        $shop = $shift->shop ?? Shop::find($shopId);

        if ($shop?->feature('skill_score') && $totalScore < $shift->required_score) {
            $warnings[] = [
                'level' => 'info',
                'msg' => "建議總分 {$totalScore} / {$shift->required_score}（參考）",
            ];
        }

        if ($shop?->feature('senior_required') && $seniorCount < $shift->min_senior_count) {
            $warnings[] = [
                'level' => 'block_warn',
                'msg' => "高階員工 {$seniorCount} / {$shift->min_senior_count}",
            ];
        }

        if ($headcount < $shift->min_headcount) {
            $warnings[] = [
                'level' => 'block_warn',
                'msg' => "人數 {$headcount} / {$shift->min_headcount}",
            ];
        }

        if ($shift->max_headcount && $headcount > $shift->max_headcount) {
            $warnings[] = [
                'level' => 'info',
                'msg' => "超過建議人數（{$headcount} / {$shift->max_headcount}）",
            ];
        }

        // 站別覆蓋
        if ($shop?->feature('stations')) {
            $shift->loadMissing('requiredStations:id,name');
            foreach ($shift->requiredStations as $station) {
                $min = (int) ($station->pivot->min_count ?? 1);
                $covered = $entries->filter(fn ($e) => $e->employee?->stations?->pluck('id')->contains($station->id))->count();
                if ($covered < $min) {
                    $warnings[] = [
                        'level' => 'block_warn',
                        'msg' => "站別「{$station->name}」{$covered} / {$min}",
                    ];
                }
            }
        }

        return [
            'total_score' => $totalScore,
            'senior_count' => $seniorCount,
            'headcount' => $headcount,
            'warnings' => $warnings,
        ];
    }

    /**
     * 把 HH:MM 轉成分鐘範圍 [start, end]，跨日時 end > 1440
     */
    private function minutesRange(string $start, string $end): array
    {
        $s = $this->hhmmToMinutes($start);
        $e = $this->hhmmToMinutes($end);
        if ($e <= $s) $e += 1440; // cross midnight
        return [$s, $e];
    }

    private function hhmmToMinutes(string $hhmm): int
    {
        $parts = explode(':', substr($hhmm, 0, 5));
        return ((int) $parts[0]) * 60 + ((int) ($parts[1] ?? 0));
    }

    private function rangesOverlap(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        return $aStart < $bEnd && $bStart < $aEnd;
    }

    private function countConsecutiveDays(int $employeeId, CarbonImmutable $targetDate): int
    {
        $count = 1;
        $check = $targetDate->subDay();
        while (
            ScheduleEntry::where('employee_id', $employeeId)
                ->where('date', $check->toDateString())
                ->exists()
        ) {
            $count++;
            $check = $check->subDay();
            if ($count > 30) {
                break;
            }
        }

        return $count;
    }
}
