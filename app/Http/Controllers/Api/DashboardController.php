<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Schedule;
use App\Models\ScheduleEntry;
use App\Models\ShiftTemplate;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // TODO: 之後用 auth user 的 current_shop_id
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop found'], 404);
        }

        $today = CarbonImmutable::today();
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);

        $weekSchedule = Schedule::query()
            ->where('shop_id', $shop->id)
            ->where('week_start_date', $weekStart->toDateString())
            ->first();

        $weekEntriesCount = $weekSchedule
            ? ScheduleEntry::where('schedule_id', $weekSchedule->id)->count()
            : 0;

        $todayShifts = $this->buildTodayShifts($shop, $today);

        $pendingLeavesCount = LeaveRequest::query()
            ->whereHas('employee', fn ($q) => $q->where('shop_id', $shop->id))
            ->where('status', 'pending')
            ->count();

        $todayHeadcount = $weekSchedule
            ? ScheduleEntry::where('schedule_id', $weekSchedule->id)
                ->whereDate('date', $today->toDateString())
                ->distinct('employee_id')
                ->count('employee_id')
            : 0;

        $activeEmployeeCount = Employee::where('shop_id', $shop->id)->active()->count();

        // Feature 模組過濾:payroll 關閉時就連算都不算,API 也不回傳
        $stats = [
            ['label' => '本週待排班次', 'value' => (string) $weekEntriesCount, 'hint' => '已排 7 天分配', 'tone' => 'neutral'],
            ['label' => '待審請假', 'value' => (string) $pendingLeavesCount, 'hint' => $pendingLeavesCount > 0 ? '需盡快審核' : '無待審', 'tone' => $pendingLeavesCount > 0 ? 'warn' : 'neutral'],
        ];
        if ($shop->feature('payroll')) {
            $laborCost = $this->buildWeeklyLaborCost($shop->id, $weekStart);
            $stats[] = ['label' => '本週人事成本', 'value' => 'NT$ '.number_format($laborCost['total']), 'hint' => $laborCost['coverage'], 'tone' => 'neutral'];
        }
        $stats[] = ['label' => '今日上班人數', 'value' => (string) $todayHeadcount, 'hint' => '尖峰 15:00-19:00', 'tone' => 'neutral'];

        $pendingLeaves = LeaveRequest::query()
            ->whereHas('employee', fn ($q) => $q->where('shop_id', $shop->id))
            ->where('status', 'pending')
            ->with('employee:id,name')
            ->orderBy('submitted_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($leave) => [
                'id' => $leave->id,
                'name' => $leave->employee?->name ?? '?',
                'type' => $this->leaveTypeLabel($leave->type),
                'range' => $this->formatLeaveRange($leave),
                'submitted' => $leave->submitted_at?->diffForHumans() ?? '-',
                'reason' => $leave->reason ?? '',
            ]);

        $scheduledTodayIds = $weekSchedule
            ? ScheduleEntry::where('schedule_id', $weekSchedule->id)
                ->whereDate('date', $today->toDateString())
                ->pluck('employee_id')
                ->unique()
            : collect();
        $unfilledAvailability = Employee::where('shop_id', $shop->id)
            ->active()
            ->whereNotIn('id', $scheduledTodayIds)
            ->limit(4)
            ->get(['id', 'name'])
            ->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'daysLeft' => '今天截止',
            ]);

        return response()->json([
            'shop' => ['id' => $shop->id, 'name' => $shop->name],
            'today_label' => $today->locale('zh_TW')->isoFormat('YYYY 年 M 月 D 日 dddd'),
            'greeting' => $this->buildGreeting($shop, $todayHeadcount),
            'stats' => $stats,
            'today_shifts' => $todayShifts,
            'pending_leaves' => $pendingLeaves,
            'unfilled_availability' => $unfilledAvailability,
        ]);
    }

    private function buildTodayShifts(Shop $shop, CarbonImmutable $today): array
    {
        $shopId = $shop->id;
        // Feature 開關決定要不要算 / 送 那些欄位
        $useStations = $shop->feature('stations');
        $useSenior = $shop->feature('senior_required');
        $useScore = $shop->feature('skill_score');

        $templatesQuery = ShiftTemplate::where('shop_id', $shopId)
            ->where('is_active', true)
            ->orderBy('sort_order');
        if ($useStations) {
            $templatesQuery->with('requiredStations:id,name');
        }
        $templates = $templatesQuery->get();

        $schedule = Schedule::where('shop_id', $shopId)
            ->where('week_start_date', $today->startOfWeek(CarbonImmutable::MONDAY)->toDateString())
            ->first();

        $entryWith = ['employee:id,name,level,skill_score'];
        if ($useStations) $entryWith[] = 'employee.stations:id,name';

        $entries = $schedule
            ? ScheduleEntry::where('schedule_id', $schedule->id)
                ->where('date', $today->toDateString())
                ->with($entryWith)
                ->get()
                ->groupBy('shift_template_id')
            : collect();

        return $templates->map(function ($tpl) use ($entries, $useStations, $useSenior, $useScore) {
            $shiftEntries = $entries->get($tpl->id, collect());

            $members = $shiftEntries->map(function ($e) use ($useScore) {
                $row = [
                    'name' => $e->employee?->name ?? '?',
                    'level' => $this->levelLabel($e->employee?->level),
                ];
                if ($useScore) {
                    $row['score'] = (int) ($e->employee?->skill_score ?? 0);
                }
                return $row;
            })->values();

            $totalScore = $shiftEntries->sum(fn ($e) => $e->employee?->skill_score ?? 0);
            $seniorCount = $shiftEntries->filter(fn ($e) => in_array($e->employee?->level, ['senior', 'lead'], true))->count();
            $headcount = $shiftEntries->count();

            $warnings = [];
            if ($headcount < $tpl->min_headcount) {
                $warnings[] = "人數 {$headcount} / {$tpl->min_headcount}";
            }
            if ($useSenior && $seniorCount < $tpl->min_senior_count) {
                $warnings[] = "高階員工 {$seniorCount} / {$tpl->min_senior_count}";
            }
            // 站別覆蓋(feature: stations)
            if ($useStations) {
                foreach ($tpl->requiredStations as $station) {
                    $min = (int) ($station->pivot->min_count ?? 1);
                    $covered = $shiftEntries->filter(fn ($e) => $e->employee?->stations?->pluck('id')->contains($station->id))->count();
                    if ($covered < $min) {
                        $warnings[] = "站別「{$station->name}」{$covered} / {$min}";
                    }
                }
            }
            // 軟性參考(feature: skill_score)
            if ($useScore && $totalScore < $tpl->required_score) {
                $warnings[] = "建議總分 {$totalScore} / {$tpl->required_score}（參考）";
            }

            $row = [
                'name' => $tpl->name,
                'time' => substr($tpl->start_time, 0, 5).'–'.substr($tpl->end_time, 0, 5),
                'members' => $members,
                'warnings' => $warnings,
            ];
            // 只在功能開啟時才送對應欄位,前端就不用守
            if ($useScore) {
                $row['score'] = ['current' => $totalScore, 'required' => $tpl->required_score];
            }
            if ($useSenior) {
                $row['senior'] = ['current' => $seniorCount, 'required' => $tpl->min_senior_count];
            }
            return $row;
        })->values()->all();
    }

    /**
     * 本週人事成本估算：依時段 (end - start) × 員工時薪 加總。
     * 沒填時薪的員工不計入，但回傳 coverage 提示。
     */
    private function buildWeeklyLaborCost(int $shopId, CarbonImmutable $weekStart): array
    {
        $weekEnd = $weekStart->addDays(6);

        // 抓本週國定假日（type=special 視為加倍）
        $specialDates = Holiday::where('shop_id', $shopId)
            ->where('type', 'special')
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        // 1. 月薪人員（在職 + 有 monthly_salary）— 一週估月薪 / 4
        $monthlyTotal = 0;
        $monthlyEmployees = \App\Models\Employee::where('shop_id', $shopId)
            ->where('status', 'active')
            ->where('monthly_salary', '>', 0)
            ->get(['id', 'monthly_salary']);
        foreach ($monthlyEmployees as $emp) {
            $monthlyTotal += (int) round($emp->monthly_salary / 4);
        }

        // 2. 時薪人員 — 依本週排班時數累計，國定假日 × 2
        $entries = ScheduleEntry::query()
            ->whereHas('schedule', fn ($q) => $q->where('shop_id', $shopId))
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->with(['employee:id,hourly_wage,monthly_salary', 'shiftTemplate:id,start_time,end_time'])
            ->get();

        $hourlyTotal = 0;
        $hourlyCounted = 0;
        $holidayShifts = 0;
        $uncovered = 0;
        foreach ($entries as $e) {
            $emp = $e->employee;
            if (! $emp) continue;
            if ($emp->monthly_salary && $emp->monthly_salary > 0) continue;

            $wage = (int) ($emp->hourly_wage ?? 0);
            $tpl = $e->shiftTemplate;
            if (! $tpl) continue;

            $start = CarbonImmutable::parse($tpl->start_time);
            $end = CarbonImmutable::parse($tpl->end_time);
            $hours = max(0, ($end->getTimestamp() - $start->getTimestamp()) / 3600);

            $multiplier = in_array($e->date->toDateString(), $specialDates, true) ? 2.0 : 1.0;
            if ($multiplier > 1) $holidayShifts++;

            if ($wage > 0) {
                $hourlyTotal += (int) round($hours * $wage * $multiplier);
                $hourlyCounted++;
            } else {
                $uncovered++;
            }
        }

        $total = $monthlyTotal + $hourlyTotal;
        $parts = [];
        if ($monthlyEmployees->count() > 0) $parts[] = $monthlyEmployees->count().' 位月薪';
        if ($hourlyCounted > 0) $parts[] = $hourlyCounted.' 班時薪';
        if ($holidayShifts > 0) $parts[] = "含國定假日 {$holidayShifts} 班加倍";
        if ($uncovered > 0) $parts[] = $uncovered.' 班未設薪';
        $coverage = $parts ? implode('、', $parts) : '本週無排班';

        return ['total' => $total, 'coverage' => $coverage];
    }

    private function buildGreeting(Shop $shop, int $todayCount): string
    {
        $hour = (int) now()->format('H');
        $greet = match (true) {
            $hour < 11 => '早安',
            $hour < 14 => '午安',
            $hour < 18 => '下午好',
            default => '晚安',
        };
        // 用目前登入者的名字（LINE 登入會自動同步 LINE displayName）；fallback 用 shop owner
        $name = Auth::user()?->name ?? $shop->owner?->name ?? '老闆';

        return "{$greet}，{$name}。今天有 {$todayCount} 位員工排班。";
    }

    private function levelLabel(?string $level): string
    {
        return match ($level) {
            'lead' => '領班',
            'senior' => '熟手',
            'junior' => '初階',
            'trainee' => '新手',
            default => '?',
        };
    }

    private function leaveTypeLabel(string $type): string
    {
        return match ($type) {
            'personal' => '事假',
            'sick' => '病假',
            'annual' => '特休',
            'funeral' => '喪假',
            'marriage' => '婚假',
            default => '其他',
        };
    }

    private function formatLeaveRange(LeaveRequest $leave): string
    {
        $start = $leave->start_datetime;
        $end = $leave->end_datetime;
        $sameDay = $start->isSameDay($end);
        $fullDay = $sameDay
            && $start->format('H:i') === '00:00'
            && $end->format('H:i') === '23:59';

        if ($fullDay) {
            return $start->format('n/j').' 全天';
        }
        if ($sameDay) {
            return $start->format('n/j').' '.$start->format('H:i').'–'.$end->format('H:i');
        }

        return $start->format('n/j H:i').' → '.$end->format('n/j H:i');
    }
}
