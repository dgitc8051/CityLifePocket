<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shop;
use App\Models\ShopSalaryMultiplier;
use Carbon\CarbonImmutable;

/**
 * 把出勤紀錄拆成「正常工時 + 各種倍率加班時數」。
 *
 * 流程：
 *   1. 對每一筆 attendance_record，依當天類型（平日/休息日/國定假日）分類
 *   2. 計算「應有時數」（scheduled hours）與「實際打卡時數」
 *   3. 若實際 > 應有 → 加班時數，依倍率拆成各個 bucket
 *   4. 加班時數只有店家核可後（overtime_minutes_approved > 0）才算薪資
 *
 * 注意：演算法不寫死台灣勞基法。倍率與時段切點全部由 shop_salary_multipliers 控制。
 */
class SalaryCalculator
{
    /** @var array<int, true> 國定假日快取 */
    private array $holidayDates = [];

    public function __construct(private Shop $shop)
    {
        // 快取此店家所有國定假日（type=public 視為國定）
        Holiday::where('shop_id', $shop->id)
            ->get(['date', 'type'])
            ->each(function ($h) {
                $this->holidayDates[$h->date->toDateString()] = true;
            });
    }

    /**
     * 計算員工某時間範圍內的時數明細
     *
     * @return array{
     *   work_minutes: int,                 // 總實際打卡時數（分鐘）
     *   late_minutes: int,                 // 遲到累計分鐘
     *   ot_detected_minutes: int,          // 系統偵測加班（未核可）
     *   ot_approved_minutes: int,          // 店家核可加班
     *   buckets: array<int, array{
     *     multiplier_id: int,
     *     label: string,
     *     multiplier: float,
     *     minutes: int,
     *   }>,
     *   records: array<int, array>
     * }
     */
    public function calculateForEmployee(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $multipliers = $this->shop->salaryMultipliers()->where('is_active', true)->get();

        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->whereBetween('clocked_in_at', [$from->startOfDay(), $to->endOfDay()])
            ->with('scheduleEntry.shiftTemplate:id,start_time,end_time')
            ->orderBy('clocked_in_at')
            ->get();

        $totalWork = 0;
        $totalLate = 0;
        $totalOtDetected = 0;
        $totalOtApproved = 0;

        // bucket[multiplier_id] = ['label' => ..., 'multiplier' => ..., 'minutes' => 0]
        $buckets = [];
        foreach ($multipliers as $m) {
            $buckets[$m->id] = [
                'multiplier_id' => $m->id,
                'label' => $m->label,
                'multiplier' => (float) $m->multiplier,
                'condition_type' => $m->condition_type,
                'minutes' => 0,
            ];
        }

        $recordList = [];

        foreach ($records as $r) {
            if (! $r->clocked_in_at || ! $r->clocked_out_at) {
                $recordList[] = $this->serializeRecord($r, null, null);
                continue;
            }

            $workedMinutes = (int) $r->clocked_out_at->diffInMinutes($r->clocked_in_at);
            $totalWork += $workedMinutes;
            $totalLate += (int) $r->late_minutes;
            $totalOtDetected += (int) $r->overtime_minutes_detected;
            $totalOtApproved += (int) $r->overtime_minutes_approved;

            // 只有核可的加班時數會被分到 buckets
            $otMinutes = (int) $r->overtime_minutes_approved;
            $dayType = $this->dayType($r->clocked_in_at);

            $bucketsForRecord = $this->distributeOvertime($otMinutes, $dayType, $multipliers);
            foreach ($bucketsForRecord as $mid => $minutes) {
                $buckets[$mid]['minutes'] += $minutes;
            }

            $recordList[] = $this->serializeRecord($r, $workedMinutes, $bucketsForRecord);
        }

        return [
            'work_minutes' => $totalWork,
            'late_minutes' => $totalLate,
            'ot_detected_minutes' => $totalOtDetected,
            'ot_approved_minutes' => $totalOtApproved,
            'buckets' => array_values($buckets),
            'records' => $recordList,
        ];
    }

    /**
     * 將某筆加班分鐘拆到符合條件的倍率 bucket
     *
     * @param  array<int, ShopSalaryMultiplier>|\Illuminate\Support\Collection  $multipliers
     * @return array<int, int>  multiplier_id => minutes
     */
    private function distributeOvertime(int $otMinutes, string $dayType, $multipliers): array
    {
        if ($otMinutes <= 0) return [];

        // 依當天類型過濾出可用 multiplier，並按 hours_from 排序
        $applicable = collect($multipliers)
            ->filter(function ($m) use ($dayType) {
                if ($m->condition_type === 'holiday') return $dayType === 'holiday';
                if ($m->condition_type === 'rest_day_ot') return $dayType === 'rest_day';
                if ($m->condition_type === 'weekday_ot') return $dayType === 'weekday';
                return false;
            })
            ->sortBy(fn ($m) => data_get($m->condition_json, 'hours_from', 0))
            ->values();

        $result = [];
        $remaining = $otMinutes;
        $cursor = 0; // 已分配的小時數（從加班 0 小時開始）

        foreach ($applicable as $m) {
            if ($remaining <= 0) break;

            $from = (float) data_get($m->condition_json, 'hours_from', 0);
            $to = data_get($m->condition_json, 'hours_to');
            $to = $to === null ? PHP_INT_MAX : (float) $to;

            // 整段時段都套這個倍率
            // 國定假日類型可能沒有 condition_json → 整筆都套
            if ($m->condition_type === 'holiday' && empty($m->condition_json)) {
                $result[$m->id] = ($result[$m->id] ?? 0) + $remaining;
                $remaining = 0;
                break;
            }

            // 此 bucket 的分鐘範圍（換算 hours_from/hours_to → minutes）
            $fromMin = (int) ($from * 60);
            $toMin = $to === PHP_INT_MAX ? PHP_INT_MAX : (int) ($to * 60);

            // 跳過已經越過的段落
            if ($cursor >= $toMin) continue;
            // 還沒到這個段落（理論上不該發生，因為已排序）
            if ($cursor < $fromMin) $cursor = $fromMin;

            $segment = min($remaining, $toMin - $cursor);
            if ($segment > 0) {
                $result[$m->id] = ($result[$m->id] ?? 0) + $segment;
                $cursor += $segment;
                $remaining -= $segment;
            }
        }

        return $result;
    }

    private function dayType(CarbonImmutable|\Carbon\Carbon|\DateTimeInterface $date): string
    {
        $d = CarbonImmutable::parse($date)->toDateString();
        if (isset($this->holidayDates[$d])) return 'holiday';
        // 沿用之前的 settings：weekend = rest_day（週六、週日）。店家未來可改
        $dow = CarbonImmutable::parse($date)->dayOfWeek; // 0=Sun..6=Sat
        if ($dow === 0 || $dow === 6) return 'rest_day';
        return 'weekday';
    }

    private function serializeRecord(AttendanceRecord $r, ?int $workMinutes, ?array $bucketsForRecord): array
    {
        return [
            'id' => $r->id,
            'date' => $r->clocked_in_at?->toDateString(),
            'clocked_in_at' => $r->clocked_in_at?->toIso8601String(),
            'clocked_out_at' => $r->clocked_out_at?->toIso8601String(),
            'work_minutes' => $workMinutes,
            'late_minutes' => (int) $r->late_minutes,
            'ot_detected_minutes' => (int) $r->overtime_minutes_detected,
            'ot_approved_minutes' => (int) $r->overtime_minutes_approved,
            'ot_approved' => (int) $r->overtime_minutes_approved > 0,
            'pending_approval' => (int) $r->overtime_minutes_detected > 0 && (int) $r->overtime_minutes_approved === 0,
            'status' => $r->status,
            'day_type' => $r->clocked_in_at ? $this->dayType($r->clocked_in_at) : null,
            'buckets' => $bucketsForRecord ?? [],
        ];
    }
}
