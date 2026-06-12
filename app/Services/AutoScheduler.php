<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAvailability;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\ScheduleEntry;
use App\Models\ShiftTemplate;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 一鍵排班演算法。
 *
 * 核心：對每個 (日, 時段) slot，按「最難排」順序處理；
 * 每個 slot 選候選人填入，直到 min_headcount / min_senior_count / station_requirements
 * 三個硬約束都滿足為止。
 *
 * 完全確定性 — 同樣 input 永遠同樣 output。
 */
class AutoScheduler
{
    public const STRATEGY_BALANCED = 'balanced'; // 平均：本月排得少的優先
    public const STRATEGY_CHEAP = 'cheap';       // 省錢：低時薪優先
    public const STRATEGY_SENIOR = 'senior';     // 重資深：高 skill_score 優先

    private Shop $shop;
    private CarbonImmutable $weekStart;
    private int $days;
    private string $strategy;
    private bool $replace;

    /** @var Collection<int, Employee> */
    private Collection $employees;

    /** @var Collection<int, ShiftTemplate> */
    private Collection $templates;

    /** @var array<string, array<int, array{employee_id:int, shift_template_id:int, date:string}>> */
    private array $slotAssignments = [];

    /** @var array<int, array<string, true>> employee_id => [date => true] */
    private array $employeeDates = [];

    /** @var array<int, int> employee_id => count of dates this month */
    private array $monthDayCount = [];

    /** @var array<int, array<string, float>> employee_id => [date => hours_assigned] */
    private array $dailyHours = [];

    /** @var array<int, array<string, float>> employee_id => [week_start_date => hours_assigned] */
    private array $weeklyHours = [];

    /** @var array<int, float> shift_template_id => hours */
    private array $templateHours = [];

    /** @var array<int, array<string, string>> employee_id => [date|template_id => availability] */
    private array $availabilities = [];

    /** @var array<int, array<string, true>> employee_id => [week_start_date => true]，員工該週是否提交過 availability */
    private array $employeeSubmittedWeeks = [];

    /** @var array<int, array<int, true>> employee_id => [date_int => true] (date as Y-m-d) */
    private array $leaveDates = [];

    /** @var array<string, true> 國定假日（按 Y-m-d） */
    private array $holidayDates = [];

    /** @var float 假日基礎倍率（hours_from=0 的 holiday multiplier，沒有就 1.0） */
    private float $holidayMultiplier = 1.0;

    /** @var float 休息日（週末）基礎倍率 */
    private float $restDayMultiplier = 1.0;

    public function __construct(
        Shop $shop,
        string $weekStart,
        string $strategy = self::STRATEGY_BALANCED,
        bool $replace = false,
        int $days = 7,
    ) {
        $this->shop = $shop;
        $this->weekStart = CarbonImmutable::parse($weekStart)->startOfWeek(CarbonImmutable::MONDAY);
        $this->days = max(7, min(35, $days));
        $this->strategy = in_array($strategy, [self::STRATEGY_BALANCED, self::STRATEGY_CHEAP, self::STRATEGY_SENIOR], true)
            ? $strategy : self::STRATEGY_BALANCED;
        $this->replace = $replace;
    }

    /**
     * 跑演算法，回傳建議的 entry 列表 + 警告（**不寫入 DB**）。
     *
     * @return array{
     *   week_start: string,
     *   strategy: string,
     *   proposed: array<int, array{employee_id:int, employee_name:string, shift_template_id:int, shift_name:string, date:string}>,
     *   kept_existing: int,
     *   warnings: array<int, string>,
     *   summary: array{slots_total:int, slots_full:int, slots_partial:int}
     * }
     */
    public function generate(): array
    {
        $this->loadData();

        $slots = $this->buildSlots();

        // 預先填入已存在的排班（若非 replace 模式）
        if (! $this->replace) {
            $this->preloadExistingEntries();
        }

        $warnings = [];
        $slotsFull = 0;
        $slotsPartial = 0;

        foreach ($slots as $slot) {
            $tpl = $slot['template'];
            $date = $slot['date'];
            $dayOfWeek = $slot['day_of_week'];
            $key = "{$tpl->id}|{$date}";

            $maxHc = $tpl->max_headcount > 0 ? $tpl->max_headcount : 99;

            while ($this->slotHeadcount($key) < $maxHc) {
                if ($this->slotConstraintsMet($key, $tpl)) {
                    break;
                }

                $candidate = $this->pickCandidate($date, $dayOfWeek, $tpl, $key);
                if (! $candidate) {
                    break;
                }

                $this->assign($key, $candidate->id, $tpl->id, $date);
            }

            if ($this->slotConstraintsMet($key, $tpl)) {
                $slotsFull++;
            } else {
                $slotsPartial++;
                $warnings[] = $this->describeUnmetConstraints($key, $tpl, $date);
            }
        }

        // 整理 output
        $proposed = [];
        $keptExisting = 0;
        foreach ($this->slotAssignments as $key => $rows) {
            foreach ($rows as $row) {
                $proposed[] = [
                    'employee_id' => $row['employee_id'],
                    'employee_name' => $this->employees->firstWhere('id', $row['employee_id'])?->name ?? '?',
                    'shift_template_id' => $row['shift_template_id'],
                    'shift_name' => $this->templates->firstWhere('id', $row['shift_template_id'])?->name ?? '?',
                    'date' => $row['date'],
                    'existing' => $row['existing'] ?? false,
                ];
                if (! empty($row['existing'])) {
                    $keptExisting++;
                }
            }
        }

        return [
            'week_start' => $this->weekStart->toDateString(),
            'strategy' => $this->strategy,
            'proposed' => $proposed,
            'kept_existing' => $keptExisting,
            'warnings' => $warnings,
            'summary' => [
                'slots_total' => count($slots),
                'slots_full' => $slotsFull,
                'slots_partial' => $slotsPartial,
            ],
        ];
    }

    // ===== 載入資料 =====

    private function loadData(): void
    {
        $this->employees = Employee::where('shop_id', $this->shop->id)
            ->where('status', 'active')
            ->with('stations:id') // pivot 包含 is_primary
            ->get();

        $this->templates = ShiftTemplate::where('shop_id', $this->shop->id)
            ->where('is_active', true)
            ->with('requiredStations:id')
            ->orderBy('sort_order')
            ->get();

        // 預先算每個 template 的時數
        foreach ($this->templates as $tpl) {
            $this->templateHours[$tpl->id] = $this->computeTemplateHours($tpl);
        }

        // 計算範圍涵蓋的所有週起始日（週一）
        $weekStarts = [];
        $weeksNeeded = (int) ceil($this->days / 7);
        for ($w = 0; $w < $weeksNeeded; $w++) {
            $weekStarts[] = $this->weekStart->addWeeks($w)->toDateString();
        }

        // Availability for all weeks in range, 'unavailable' explicitly blocks; missing assume OK
        $availRows = EmployeeAvailability::whereIn('employee_id', $this->employees->pluck('id'))
            ->whereIn('week_start_date', $weekStarts)
            ->get();
        foreach ($availRows as $a) {
            // day_of_week 0=日 ~ 6=六；週一起的 offset: Mon→0, Tue→1, ..., Sun→6
            $offset = $a->day_of_week === 0 ? 6 : $a->day_of_week - 1;
            $weekStartDate = CarbonImmutable::parse($a->week_start_date);
            $weekStartKey = $weekStartDate->toDateString(); // Carbon → string，不然不能當 array key
            $dateKey = $weekStartDate->addDays($offset)->toDateString();
            $this->availabilities[$a->employee_id][$dateKey.'|'.$a->shift_template_id] = $a->availability;
            $this->employeeSubmittedWeeks[$a->employee_id][$weekStartKey] = true;
        }

        // Pre-load leaves overlapping the full range
        $weekEnd = $this->weekStart->addDays($this->days - 1);
        $leaves = LeaveRequest::whereIn('employee_id', $this->employees->pluck('id'))
            ->whereIn('status', ['pending', 'approved'])
            ->where('start_datetime', '<=', $weekEnd->endOfDay())
            ->where('end_datetime', '>=', $this->weekStart->startOfDay())
            ->get();
        foreach ($leaves as $l) {
            $start = CarbonImmutable::parse($l->start_datetime)->startOfDay();
            $end = CarbonImmutable::parse($l->end_datetime)->endOfDay();
            $cursor = $start;
            while ($cursor <= $end) {
                $this->leaveDates[$l->employee_id][$cursor->toDateString()] = true;
                $cursor = $cursor->addDay();
            }
        }

        // Pre-load monthly day counts — 改用「每個 (員工, 月份) 一個 key」修正跨月 bug
        // 範圍可能涵蓋 1~2 個月
        $rangeEnd = $this->weekStart->addDays($this->days - 1);
        $startMonth = $this->weekStart->startOfMonth();
        $endMonth = $rangeEnd->endOfMonth();
        $monthEntries = ScheduleEntry::query()
            ->whereHas('schedule', fn ($q) => $q->where('shop_id', $this->shop->id))
            ->whereBetween('date', [$startMonth->toDateString(), $endMonth->toDateString()])
            ->get(['employee_id', 'date']);
        foreach ($monthEntries as $e) {
            $ym = substr($e->date->toDateString(), 0, 7); // YYYY-MM
            $key = $e->employee_id.'|'.$ym;
            $this->monthDayCount[$key] = ($this->monthDayCount[$key] ?? 0) + 1;
        }

        // 國定假日（用來判斷 day type）
        Holiday::where('shop_id', $this->shop->id)
            ->whereBetween('date', [$this->weekStart->toDateString(), $rangeEnd->toDateString()])
            ->get(['date'])
            ->each(function ($h) {
                $this->holidayDates[$h->date->toDateString()] = true;
            });

        // 載入店家薪資倍率，給 cheap 策略用
        // 取「hours_from=0」的 multiplier 當基礎倍率（代表整段加班 / 整天的最低成本倍率）
        foreach ($this->shop->salaryMultipliers()->where('is_active', true)->get() as $m) {
            $hoursFrom = (float) data_get($m->condition_json, 'hours_from', 0);
            if ($m->condition_type === 'holiday' && $hoursFrom === 0.0) {
                $this->holidayMultiplier = max($this->holidayMultiplier, (float) $m->multiplier);
            }
            if ($m->condition_type === 'rest_day_ot' && $hoursFrom === 0.0) {
                $this->restDayMultiplier = max($this->restDayMultiplier, (float) $m->multiplier);
            }
        }
    }

    private function ymOf(string $date): string
    {
        return substr($date, 0, 7);
    }

    private function preloadExistingEntries(): void
    {
        $weekEnd = $this->weekStart->addDays($this->days - 1);
        $existing = ScheduleEntry::query()
            ->whereHas('schedule', fn ($q) => $q->where('shop_id', $this->shop->id))
            ->whereBetween('date', [$this->weekStart->toDateString(), $weekEnd->toDateString()])
            ->get();

        foreach ($existing as $e) {
            $key = "{$e->shift_template_id}|{$e->date->toDateString()}";
            $dateStr = $e->date->toDateString();
            $hours = $this->templateHours[$e->shift_template_id] ?? 0;

            $this->slotAssignments[$key][] = [
                'employee_id' => $e->employee_id,
                'shift_template_id' => $e->shift_template_id,
                'date' => $dateStr,
                'existing' => true,
            ];
            $this->employeeDates[$e->employee_id][$dateStr] = true;
            $this->dailyHours[$e->employee_id][$dateStr] = ($this->dailyHours[$e->employee_id][$dateStr] ?? 0) + $hours;
            $weekKey = CarbonImmutable::parse($dateStr)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            $this->weeklyHours[$e->employee_id][$weekKey] = ($this->weeklyHours[$e->employee_id][$weekKey] ?? 0) + $hours;
        }
    }

    private function computeTemplateHours(ShiftTemplate $tpl): float
    {
        $start = strtotime("1970-01-01 {$tpl->start_time}");
        $end = strtotime("1970-01-01 {$tpl->end_time}");
        if ($end <= $start) $end += 86400;
        return max(0, ($end - $start) / 3600);
    }

    // ===== Slot 處理 =====

    /**
     * @return array<int, array{date:string, day_of_week:int, template:ShiftTemplate, difficulty:int}>
     */
    private function buildSlots(): array
    {
        $slots = [];
        for ($i = 0; $i < $this->days; $i++) {
            $d = $this->weekStart->addDays($i);
            $dow = $d->dayOfWeek; // 0=日 ~ 6=六
            foreach ($this->templates as $tpl) {
                if (! ($tpl->days_of_week_bitmask & (1 << $dow))) {
                    continue;
                }
                $slots[] = [
                    'date' => $d->toDateString(),
                    'day_of_week' => $dow,
                    'template' => $tpl,
                    'difficulty' => $this->computeDifficulty($tpl),
                ];
            }
        }

        usort($slots, fn ($a, $b) => $b['difficulty'] <=> $a['difficulty']);

        return $slots;
    }

    private function computeDifficulty(ShiftTemplate $tpl): int
    {
        return $tpl->requiredStations->count() * 100
            + ($tpl->min_senior_count ?? 0) * 30
            + ($tpl->min_headcount ?? 0) * 10
            + (int) (($tpl->required_score ?? 0) / 10);
    }

    private function slotHeadcount(string $key): int
    {
        return count($this->slotAssignments[$key] ?? []);
    }

    private function slotConstraintsMet(string $key, ShiftTemplate $tpl): bool
    {
        $members = $this->slotMembers($key);
        $headcount = $members->count();

        if ($headcount < $tpl->min_headcount) return false;

        if ($this->shop->feature('senior_required')) {
            $seniorCount = $members->filter(fn ($e) => in_array($e->level, ['senior', 'lead'], true))->count();
            if ($seniorCount < ($tpl->min_senior_count ?? 0)) return false;
        }

        if ($this->shop->feature('stations')) {
            foreach ($tpl->requiredStations as $station) {
                $min = (int) ($station->pivot->min_count ?? 1);
                $covered = $members->filter(fn ($e) => $e->stations->pluck('id')->contains($station->id))->count();
                if ($covered < $min) return false;
            }
        }

        return true;
    }

    private function describeUnmetConstraints(string $key, ShiftTemplate $tpl, string $date): string
    {
        $members = $this->slotMembers($key);
        $headcount = $members->count();

        $parts = [];
        if ($headcount < $tpl->min_headcount) {
            $parts[] = "人數 {$headcount}/{$tpl->min_headcount}";
        }
        if ($this->shop->feature('senior_required')) {
            $seniorCount = $members->filter(fn ($e) => in_array($e->level, ['senior', 'lead'], true))->count();
            if ($seniorCount < ($tpl->min_senior_count ?? 0)) {
                $parts[] = "高階 {$seniorCount}/{$tpl->min_senior_count}";
            }
        }
        if ($this->shop->feature('stations')) {
            foreach ($tpl->requiredStations as $station) {
                $min = (int) ($station->pivot->min_count ?? 1);
                $covered = $members->filter(fn ($e) => $e->stations->pluck('id')->contains($station->id))->count();
                if ($covered < $min) {
                    $parts[] = "{$station->name} {$covered}/{$min}";
                }
            }
        }

        return "{$date} {$tpl->name}：".implode('、', $parts);
    }

    private function slotMembers(string $key): Collection
    {
        $ids = collect($this->slotAssignments[$key] ?? [])->pluck('employee_id');

        return $this->employees->whereIn('id', $ids);
    }

    // ===== 挑候選人 =====

    private function pickCandidate(string $date, int $dayOfWeek, ShiftTemplate $tpl, string $key): ?Employee
    {
        $alreadyInSlot = collect($this->slotAssignments[$key] ?? [])->pluck('employee_id')->all();

        $candidates = $this->employees->filter(function (Employee $emp) use ($date, $tpl, $alreadyInSlot) {
            if (in_array($emp->id, $alreadyInSlot, true)) return false;
            if (isset($this->leaveDates[$emp->id][$date])) return false;

            $availKey = $date.'|'.$tpl->id;
            $avail = $this->availabilities[$emp->id][$availKey] ?? null;

            // 明確標 unavailable → 排除
            if ($avail === 'unavailable') return false;

            // 嚴格模式：員工該週有提交過 availability、但這個時段沒填 → 視為「不願意」
            // 這擋住「店家新建時段，員工沒重填，演算法照排」的情況
            if ($avail === null) {
                $weekStart = CarbonImmutable::parse($date)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
                if (isset($this->employeeSubmittedWeeks[$emp->id][$weekStart])) {
                    return false;
                }
            }

            return true;
        })->values();

        if ($candidates->isEmpty()) return null;

        // 優先補站別缺口
        if ($this->shop->feature('stations')) {
            $stationGap = $this->findStationGap($key, $tpl);
            if ($stationGap) {
                $stationCandidates = $candidates->filter(fn ($e) => $e->stations->pluck('id')->contains($stationGap));
                if ($stationCandidates->isNotEmpty()) {
                    $candidates = $stationCandidates;
                }
            }
        }

        // 優先補高階缺口
        if ($this->shop->feature('senior_required') && $this->needsSenior($key, $tpl)) {
            $seniorCandidates = $candidates->filter(fn ($e) => in_array($e->level, ['senior', 'lead'], true));
            if ($seniorCandidates->isNotEmpty()) {
                $candidates = $seniorCandidates;
            }
        }

        // 按策略 + 軟限制排序
        return $this->sortByStrategy($candidates, $date, $tpl->id)->first();
    }

    private function findStationGap(string $key, ShiftTemplate $tpl): ?int
    {
        $members = $this->slotMembers($key);
        foreach ($tpl->requiredStations as $station) {
            $min = (int) ($station->pivot->min_count ?? 1);
            $covered = $members->filter(fn ($e) => $e->stations->pluck('id')->contains($station->id))->count();
            if ($covered < $min) {
                return $station->id;
            }
        }

        return null;
    }

    private function needsSenior(string $key, ShiftTemplate $tpl): bool
    {
        if (($tpl->min_senior_count ?? 0) <= 0) return false;
        $members = $this->slotMembers($key);
        $seniorCount = $members->filter(fn ($e) => in_array($e->level, ['senior', 'lead'], true))->count();

        return $seniorCount < $tpl->min_senior_count;
    }

    private function sortByStrategy(Collection $candidates, string $date, int $templateId): Collection
    {
        // 先算每個 candidate 的軟限制 penalty + availability priority + station priority，併進 sort key
        return match ($this->strategy) {
            self::STRATEGY_CHEAP => $candidates->sortBy([
                fn ($a, $b) => $this->availabilityPriority($a, $date, $templateId) <=> $this->availabilityPriority($b, $date, $templateId),
                fn ($a, $b) => $this->stationPriority($a, $templateId) <=> $this->stationPriority($b, $templateId),
                fn ($a, $b) => $this->softPenalty($a, $date, $templateId) <=> $this->softPenalty($b, $date, $templateId),
                fn ($a, $b) => $this->effectiveWage($a, $date) <=> $this->effectiveWage($b, $date),
                fn ($a, $b) => $this->daysAssigned($a->id, $date) <=> $this->daysAssigned($b->id, $date),
            ])->values(),
            self::STRATEGY_SENIOR => $candidates->sortBy([
                fn ($a, $b) => $this->availabilityPriority($a, $date, $templateId) <=> $this->availabilityPriority($b, $date, $templateId),
                fn ($a, $b) => $this->stationPriority($a, $templateId) <=> $this->stationPriority($b, $templateId),
                fn ($a, $b) => $this->softPenalty($a, $date, $templateId) <=> $this->softPenalty($b, $date, $templateId),
                fn ($a, $b) => $b->skill_score <=> $a->skill_score,
                fn ($a, $b) => $this->daysAssigned($a->id, $date) <=> $this->daysAssigned($b->id, $date),
            ])->values(),
            default => $candidates->sortBy([
                fn ($a, $b) => $this->availabilityPriority($a, $date, $templateId) <=> $this->availabilityPriority($b, $date, $templateId),
                fn ($a, $b) => $this->stationPriority($a, $templateId) <=> $this->stationPriority($b, $templateId),
                fn ($a, $b) => $this->softPenalty($a, $date, $templateId) <=> $this->softPenalty($b, $date, $templateId),
                fn ($a, $b) => $this->daysAssigned($a->id, $date) <=> $this->daysAssigned($b->id, $date),
                fn ($a, $b) => $b->skill_score <=> $a->skill_score,
            ])->values(),
        };
    }

    /**
     * 可上時段優先級：available=0、maybe=1、未填=2。值越小越優先。
     * （unavailable 已在 pickCandidate 排除掉）
     */
    private function availabilityPriority(Employee $emp, string $date, int $templateId): int
    {
        $avail = $this->availabilities[$emp->id][$date.'|'.$templateId] ?? null;
        return match ($avail) {
            'available' => 0,
            'maybe' => 1,
            default => 2,
        };
    }

    /**
     * 站別優先級：員工主要站別命中此時段需求 = 0、會做但非主要 = 1、不會做 = 2。
     * 為 0 的優先排（讓擅長的人去做擅長的事）。站別功能關閉時統一回 0。
     */
    private function stationPriority(Employee $emp, int $templateId): int
    {
        if (! $this->shop->feature('stations')) return 0;
        $tpl = $this->templates->firstWhere('id', $templateId);
        if (! $tpl || $tpl->requiredStations->isEmpty()) return 1;

        $requiredIds = $tpl->requiredStations->pluck('id');
        $primaryStations = $emp->stations->filter(fn ($s) => $s->pivot->is_primary ?? false)->pluck('id');
        $allStations = $emp->stations->pluck('id');

        if ($primaryStations->intersect($requiredIds)->isNotEmpty()) return 0;
        if ($allStations->intersect($requiredIds)->isNotEmpty()) return 1;
        return 2;
    }

    /**
     * 排序「省錢」用：
     * - 月薪人員視為 0（邊際成本 0）
     * - 時薪人員：時薪 × 該日適用倍率（國定假日 / 休息日 / 平日）
     *
     * 注意：這是 ranking key，不是實際薪資；用 *1000 取整以利 int sort。
     */
    private function effectiveWage(Employee $emp, ?string $date = null): int
    {
        if ($emp->monthly_salary && $emp->monthly_salary > 0) {
            return 0;
        }
        $base = $emp->hourly_wage ?? 9999;
        if (! $date) return $base * 1000;

        $multiplier = match ($this->dayTypeOf($date)) {
            'holiday' => $this->holidayMultiplier,
            'rest_day' => $this->restDayMultiplier,
            default => 1.0,
        };

        return (int) round($base * $multiplier * 1000);
    }

    private function dayTypeOf(string $date): string
    {
        if (isset($this->holidayDates[$date])) return 'holiday';
        $dow = CarbonImmutable::parse($date)->dayOfWeek; // 0=Sun..6=Sat
        if ($dow === 0 || $dow === 6) return 'rest_day';
        return 'weekday';
    }

    private function daysAssigned(int $employeeId, ?string $contextDate = null): int
    {
        $thisWeek = count($this->employeeDates[$employeeId] ?? []);
        // 用 contextDate 對應的月份；沒給就用 week_start 的月份
        $ym = $contextDate ? $this->ymOf($contextDate) : $this->ymOf($this->weekStart->toDateString());
        $thisMonth = $this->monthDayCount[$employeeId.'|'.$ym] ?? 0;

        // 月度權重 1.0、週度權重 0.5
        return $thisMonth * 2 + $thisWeek;
    }

    private function assign(string $key, int $employeeId, int $templateId, string $date): void
    {
        $tpl = $this->templates->firstWhere('id', $templateId);
        $hoursToday = $this->templateHoursOnDate($tpl, $date, true);  // 跨日只算當日部分
        $hoursTomorrow = $this->templateHoursOnDate($tpl, $date, false); // 跨日的隔日部分

        $this->slotAssignments[$key][] = [
            'employee_id' => $employeeId,
            'shift_template_id' => $templateId,
            'date' => $date,
            'existing' => false,
        ];
        $this->employeeDates[$employeeId][$date] = true;

        $monthKey = $employeeId.'|'.$this->ymOf($date);
        $this->monthDayCount[$monthKey] = ($this->monthDayCount[$monthKey] ?? 0) + 1;

        // 當日部分
        $this->dailyHours[$employeeId][$date] = ($this->dailyHours[$employeeId][$date] ?? 0) + $hoursToday;
        // 跨日：隔日也算工時（給 daily_max_hours 用）
        if ($hoursTomorrow > 0) {
            $nextDate = CarbonImmutable::parse($date)->addDay()->toDateString();
            $this->dailyHours[$employeeId][$nextDate] = ($this->dailyHours[$employeeId][$nextDate] ?? 0) + $hoursTomorrow;
        }

        $weekKey = CarbonImmutable::parse($date)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $totalHours = $hoursToday + $hoursTomorrow;
        $this->weeklyHours[$employeeId][$weekKey] = ($this->weeklyHours[$employeeId][$weekKey] ?? 0) + $totalHours;
    }

    /**
     * 計算時段在「當日」或「隔日」的工時。跨日時段 (如 19:00-02:00)
     * 會分配給當日 19:00-24:00 = 5h 跟隔日 00:00-02:00 = 2h
     */
    private function templateHoursOnDate(?ShiftTemplate $tpl, string $date, bool $sameDay): float
    {
        if (! $tpl) return 0;
        $s = strtotime("1970-01-01 {$tpl->start_time}");
        $e = strtotime("1970-01-01 {$tpl->end_time}");
        $midnight = strtotime('1970-01-02 00:00');

        if ($e > $s) {
            // 不跨日
            return $sameDay ? ($e - $s) / 3600 : 0;
        }
        // 跨日：當日 start ~ midnight；隔日 midnight ~ end+24h
        if ($sameDay) {
            return ($midnight - $s) / 3600;
        }
        return ($e + 86400 - $midnight) / 3600;
    }

    /**
     * 軟限制懲罰分數：值越大代表「越不適合」。
     * 配工時上下限考量。
     */
    private function softPenalty(Employee $emp, string $date, int $templateId): int
    {
        $penalty = 0;
        $hours = $this->templateHours[$templateId] ?? 0;
        $weekKey = CarbonImmutable::parse($date)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $afterDaily = ($this->dailyHours[$emp->id][$date] ?? 0) + $hours;
        $afterWeekly = ($this->weeklyHours[$emp->id][$weekKey] ?? 0) + $hours;

        // 1. 每天上限超標：每超 1 小時 +100
        if ($emp->daily_max_hours && $afterDaily > $emp->daily_max_hours) {
            $penalty += (int) (($afterDaily - $emp->daily_max_hours) * 100);
        }
        // 2. 每週上限超標：每超 1 小時 +50
        if ($emp->weekly_max_hours && $afterWeekly > $emp->weekly_max_hours) {
            $penalty += (int) (($afterWeekly - $emp->weekly_max_hours) * 50);
        }
        // 3. 還沒達到每週最低工時：給 bonus（負分），鼓勵排這位
        if ($emp->weekly_min_hours && ($this->weeklyHours[$emp->id][$weekKey] ?? 0) < $emp->weekly_min_hours) {
            $penalty -= 30;
        }

        return $penalty;
    }
}
