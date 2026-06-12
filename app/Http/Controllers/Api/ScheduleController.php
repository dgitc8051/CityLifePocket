<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\ScheduleEntry;
use App\Models\ShiftTemplate;
use App\Models\Shop;
use App\Services\AuditService;
use App\Services\AutoScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $weekStart = $this->resolveWeekStart($request->query('week'));
        $days_count = max(7, min(31, (int) $request->query('days', 14)));

        $schedule = Schedule::where('shop_id', $shop->id)
            ->where('week_start_date', $weekStart->toDateString())
            ->first();

        // 抓所有 schedule_entries 在這個區間（可能跨多個 schedule，但同 shop）
        $endDate = $weekStart->addDays($days_count - 1)->toDateString();
        $entries = ScheduleEntry::whereHas('schedule', fn ($q) => $q->where('shop_id', $shop->id))
            ->whereBetween('date', [$weekStart->toDateString(), $endDate])
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'employee_id' => $e->employee_id,
                'shift_template_id' => $e->shift_template_id,
                'date' => $e->date->toDateString(),
                'status' => $e->status,
            ]);

        $employees = Employee::where('shop_id', $shop->id)
            ->with('stations:id,name,color')
            ->active()
            ->orderByDesc('skill_score')
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'level' => $e->level,
                'level_label' => match ($e->level) {
                    'lead' => '領班',
                    'senior' => '熟手',
                    'junior' => '初階',
                    'trainee' => '新手',
                    default => '?',
                },
                'skill_score' => $e->skill_score,
                'is_senior' => in_array($e->level, ['senior', 'lead'], true),
                'station_ids' => $e->stations->pluck('id')->values(),
            ]);

        $templates = ShiftTemplate::where('shop_id', $shop->id)
            ->where('is_active', true)
            ->with('requiredStations:id,name,color')
            ->orderBy('sort_order')
            ->orderBy('start_time')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'start_time' => substr($t->start_time, 0, 5),
                'end_time' => substr($t->end_time, 0, 5),
                'required_score' => $t->required_score,
                'min_senior_count' => $t->min_senior_count,
                'min_headcount' => $t->min_headcount,
                'max_headcount' => $t->max_headcount,
                'days_of_week_bitmask' => $t->days_of_week_bitmask,
                'station_requirements' => $t->requiredStations->map(fn ($s) => [
                    'station_id' => $s->id,
                    'name' => $s->name,
                    'color' => $s->color,
                    'min_count' => (int) ($s->pivot->min_count ?? 1),
                ])->values(),
            ]);

        $days = [];
        for ($i = 0; $i < $days_count; $i++) {
            $d = $weekStart->addDays($i);
            $days[] = [
                'date' => $d->toDateString(),
                'day_of_week' => $d->dayOfWeek,
                'day_label' => $d->format('j'),         // 號
                'weekday_label' => $d->locale('zh_TW')->isoFormat('dd'), // 一二三...
                'is_today' => $d->isToday(),
                'is_weekend' => in_array($d->dayOfWeek, [0, 6], true),
            ];
        }

        // 員工可上時段（unavailable 視為硬性不可貼）
        // 涵蓋的所有週起始日
        $weeksNeeded = (int) ceil($days_count / 7);
        $weekStarts = [];
        for ($w = 0; $w < $weeksNeeded; $w++) {
            $weekStarts[] = $weekStart->addWeeks($w)->toDateString();
        }
        $availRows = \App\Models\EmployeeAvailability::whereIn('week_start_date', $weekStarts)
            ->whereHas('employee', fn ($q) => $q->where('shop_id', $shop->id))
            ->get(['employee_id', 'week_start_date', 'day_of_week', 'shift_template_id', 'availability']);

        // 轉成 { "empId|date|shiftId": availability } + 記錄員工該週是否提交過
        $availMap = [];
        $submittedWeeks = []; // { empId: { weekStart: true } }
        foreach ($availRows as $a) {
            $offset = $a->day_of_week === 0 ? 6 : $a->day_of_week - 1;
            $weekStartStr = CarbonImmutable::parse($a->week_start_date)->toDateString();
            $date = CarbonImmutable::parse($a->week_start_date)->addDays($offset)->toDateString();
            $availMap["{$a->employee_id}|{$date}|{$a->shift_template_id}"] = $a->availability;
            $submittedWeeks[$a->employee_id][$weekStartStr] = true;
        }

        // 員工請假覆蓋的日期（pending 跟 approved 都擋）
        $rangeStart = $weekStart->toDateString();
        $rangeEnd = $endDate;
        $leaves = \App\Models\LeaveRequest::whereIn('status', ['pending', 'approved'])
            ->whereHas('employee', fn ($q) => $q->where('shop_id', $shop->id))
            ->where('start_datetime', '<=', $rangeEnd.' 23:59:59')
            ->where('end_datetime', '>=', $rangeStart.' 00:00:00')
            ->get(['employee_id', 'start_datetime', 'end_datetime']);

        $leaveDates = []; // { empId: [date1, date2, ...] }
        foreach ($leaves as $l) {
            $start = CarbonImmutable::parse($l->start_datetime)->startOfDay();
            $end = CarbonImmutable::parse($l->end_datetime)->endOfDay();
            $cursor = $start;
            while ($cursor <= $end) {
                $ds = $cursor->toDateString();
                if ($ds >= $rangeStart && $ds <= $rangeEnd) {
                    $leaveDates[$l->employee_id][] = $ds;
                }
                $cursor = $cursor->addDay();
            }
        }
        foreach ($leaveDates as $k => $v) {
            $leaveDates[$k] = array_values(array_unique($v));
        }

        return response()->json([
            'schedule' => $schedule ? [
                'id' => $schedule->id,
                'status' => $schedule->status,
                'week_start_date' => $schedule->week_start_date->toDateString(),
                'published_at' => $schedule->published_at?->toIso8601String(),
            ] : null,
            'week_start' => $weekStart->toDateString(),
            'days' => $days,
            'templates' => $templates,
            'employees' => $employees,
            'entries' => $entries,
            'availabilities' => $availMap,  // 給「複製貼上」的紅綠框判斷用
            'leave_dates' => $leaveDates,   // 同上
            'submitted_weeks' => $submittedWeeks, // empId → { weekStart: true }
        ]);
    }

    public function publish(Request $request): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $weekStart = $this->resolveWeekStart($request->input('week'));

        $schedule = Schedule::firstOrCreate(
            ['shop_id' => $shop->id, 'week_start_date' => $weekStart->toDateString()],
            ['status' => 'draft', 'created_by_user_id' => $shop->owner_user_id],
        );

        $before = $schedule->toArray();
        $schedule->update([
            'status' => 'published',
            'published_at' => now(),
            'published_by_user_id' => $shop->owner_user_id,
            'version' => $schedule->version + 1,
        ]);
        AuditService::log('schedule.publish', $schedule, $before, $schedule->toArray(), $shop->id);

        // 推 LINE 通知給所有班表內員工(失敗不影響 publish 主流程)
        $notified = 0;
        try {
            $notified = app(\App\Services\Line\NotificationDispatcher::class)
                ->dispatchSchedulePublished($schedule);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Schedule.publish notify failed', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Schedule published',
            'schedule' => [
                'id' => $schedule->id,
                'status' => $schedule->status,
                'version' => $schedule->version,
            ],
            'notified_count' => $notified,
        ]);
    }

    public function copyFromWeek(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_week' => 'required|date',
            'target_week' => 'required|date',
            'replace_existing' => 'sometimes|boolean',
        ]);

        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $sourceWeekStart = CarbonImmutable::parse($data['source_week'])
            ->startOfWeek(CarbonImmutable::MONDAY);
        $targetWeekStart = CarbonImmutable::parse($data['target_week'])
            ->startOfWeek(CarbonImmutable::MONDAY);

        $sourceSchedule = Schedule::where('shop_id', $shop->id)
            ->where('week_start_date', $sourceWeekStart->toDateString())
            ->first();

        if (! $sourceSchedule) {
            return response()->json(['error' => '來源週沒有排班可複製'], 404);
        }

        $sourceEntries = ScheduleEntry::where('schedule_id', $sourceSchedule->id)->get();
        if ($sourceEntries->isEmpty()) {
            return response()->json(['error' => '來源週沒有任何排班項目'], 404);
        }

        $targetSchedule = Schedule::firstOrCreate(
            ['shop_id' => $shop->id, 'week_start_date' => $targetWeekStart->toDateString()],
            ['status' => 'draft', 'created_by_user_id' => $shop->owner_user_id],
        );

        if ($data['replace_existing'] ?? false) {
            ScheduleEntry::where('schedule_id', $targetSchedule->id)->delete();
        }

        $copied = 0;
        $skipped = 0;
        $dayShift = (int) (($targetWeekStart->getTimestamp() - $sourceWeekStart->getTimestamp()) / 86400);

        foreach ($sourceEntries as $e) {
            $newDate = CarbonImmutable::parse($e->date)->addDays($dayShift)->toDateString();

            $exists = ScheduleEntry::where('schedule_id', $targetSchedule->id)
                ->where('employee_id', $e->employee_id)
                ->where('shift_template_id', $e->shift_template_id)
                ->where('date', $newDate)
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            ScheduleEntry::create([
                'schedule_id' => $targetSchedule->id,
                'employee_id' => $e->employee_id,
                'shift_template_id' => $e->shift_template_id,
                'date' => $newDate,
                'status' => 'scheduled',
            ]);
            $copied++;
        }

        AuditService::log(
            'schedule.copy',
            $targetSchedule,
            ['source_week' => $sourceWeekStart->toDateString()],
            ['target_week' => $targetWeekStart->toDateString(), 'copied' => $copied, 'skipped' => $skipped],
            $shop->id,
        );

        return response()->json([
            'message' => "已複製 {$copied} 項，跳過 {$skipped} 項已存在",
            'copied' => $copied,
            'skipped' => $skipped,
        ]);
    }

    /**
     * 預覽：跑演算法但**不寫入 DB**，前端決定是否套用。
     */
    public function autoGeneratePreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'week' => 'required|date',
            'days' => 'sometimes|integer|min:7|max:35',
            'strategy' => 'sometimes|in:balanced,cheap,senior',
            'replace_existing' => 'sometimes|boolean',
        ]);

        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $scheduler = new AutoScheduler(
            $shop,
            $data['week'],
            $data['strategy'] ?? AutoScheduler::STRATEGY_BALANCED,
            $data['replace_existing'] ?? false,
            $data['days'] ?? 7,
        );

        return response()->json($scheduler->generate());
    }

    /**
     * 套用：跑一次演算法 + 真的寫入 DB（在 transaction 內）。
     */
    public function autoGenerateApply(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user || ! $user->canWrite('schedule')) {
            return response()->json(['error' => '無權編輯班表'], 403);
        }

        $data = $request->validate([
            'week' => 'required|date',
            'days' => 'sometimes|integer|min:7|max:35',
            'strategy' => 'sometimes|in:balanced,cheap,senior',
            'replace_existing' => 'sometimes|boolean',
        ]);

        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $scheduler = new AutoScheduler(
            $shop,
            $data['week'],
            $data['strategy'] ?? AutoScheduler::STRATEGY_BALANCED,
            $data['replace_existing'] ?? false,
            $data['days'] ?? 7,
        );
        $result = $scheduler->generate();

        $weekStart = CarbonImmutable::parse($data['week'])->startOfWeek(CarbonImmutable::MONDAY);
        $days = $data['days'] ?? 7;
        $rangeEnd = $weekStart->addDays($days - 1);

        $created = 0;
        DB::transaction(function () use ($shop, $weekStart, $rangeEnd, $days, $result, $data, &$created) {
            // 若 replace，清掉整個 range
            if (! empty($data['replace_existing'])) {
                ScheduleEntry::query()
                    ->whereHas('schedule', fn ($q) => $q->where('shop_id', $shop->id))
                    ->whereBetween('date', [$weekStart->toDateString(), $rangeEnd->toDateString()])
                    ->delete();
            }

            // 為涵蓋的每一週確保 Schedule row 存在
            $scheduleMap = []; // weekStartDate => Schedule
            $weeksNeeded = (int) ceil($days / 7);
            for ($w = 0; $w < $weeksNeeded; $w++) {
                $ws = $weekStart->addWeeks($w);
                $sched = Schedule::firstOrCreate(
                    ['shop_id' => $shop->id, 'week_start_date' => $ws->toDateString()],
                    ['status' => 'draft', 'created_by_user_id' => $shop->owner_user_id],
                );
                $scheduleMap[$ws->toDateString()] = $sched;
            }

            foreach ($result['proposed'] as $row) {
                if (! empty($row['existing'])) continue;

                // 找出這個 entry 屬於哪一週的 Schedule
                $entryDate = CarbonImmutable::parse($row['date']);
                $entryWeekStart = $entryDate->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
                $sched = $scheduleMap[$entryWeekStart] ?? null;
                if (! $sched) continue;

                $exists = ScheduleEntry::where([
                    'schedule_id' => $sched->id,
                    'employee_id' => $row['employee_id'],
                    'shift_template_id' => $row['shift_template_id'],
                    'date' => $row['date'],
                ])->exists();
                if ($exists) continue;

                ScheduleEntry::create([
                    'schedule_id' => $sched->id,
                    'employee_id' => $row['employee_id'],
                    'shift_template_id' => $row['shift_template_id'],
                    'date' => $row['date'],
                    'status' => 'scheduled',
                ]);
                $created++;
            }

            // 用第一週的 Schedule 做 audit entity
            $firstSched = $scheduleMap[$weekStart->toDateString()];
            AuditService::log(
                'schedule.auto_generate',
                $firstSched,
                ['week' => $weekStart->toDateString(), 'days' => $days],
                ['created' => $created, 'strategy' => $data['strategy'] ?? 'balanced'],
                $shop->id,
            );
        });

        return response()->json([
            'message' => "已建立 {$created} 個排班",
            'created' => $created,
            'summary' => $result['summary'],
            'warnings' => $result['warnings'],
        ]);
    }

    /**
     * 一鍵刪除：清空目前檢視範圍內所有排班 entry。
     */
    public function clearRange(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user || ! $user->canWrite('schedule')) {
            return response()->json(['error' => '無權編輯班表'], 403);
        }

        $data = $request->validate([
            'week' => 'required|date',
            'days' => 'sometimes|integer|min:7|max:31',
        ]);

        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $weekStart = CarbonImmutable::parse($data['week'])->startOfWeek(CarbonImmutable::MONDAY);
        $days = $data['days'] ?? 14;
        $rangeEnd = $weekStart->addDays($days - 1);

        $deleted = ScheduleEntry::query()
            ->whereHas('schedule', fn ($q) => $q->where('shop_id', $shop->id))
            ->whereBetween('date', [$weekStart->toDateString(), $rangeEnd->toDateString()])
            ->delete();

        // 用 Schedule 當 audit entity（若不存在就建一個 placeholder 來記錄）
        $schedule = Schedule::firstOrCreate(
            ['shop_id' => $shop->id, 'week_start_date' => $weekStart->toDateString()],
            ['status' => 'draft', 'created_by_user_id' => $shop->owner_user_id],
        );
        AuditService::log(
            'schedule.clear',
            $schedule,
            ['week' => $weekStart->toDateString(), 'days' => $days],
            ['deleted' => $deleted],
            $shop->id,
        );

        return response()->json([
            'message' => "已刪除 {$deleted} 個排班",
            'deleted' => $deleted,
        ]);
    }

    private function resolveWeekStart(?string $weekParam): CarbonImmutable
    {
        if ($weekParam) {
            try {
                return CarbonImmutable::parse($weekParam)->startOfWeek(CarbonImmutable::MONDAY);
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY);
    }
}
