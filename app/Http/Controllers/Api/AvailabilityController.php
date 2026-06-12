<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAvailability;
use App\Models\ScheduleEntry;
use App\Models\ShiftTemplate;
use App\Models\Shop;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AvailabilityController extends Controller
{
    public function weeklyMatrix(Request $request): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $user = $request->user();
        $isManager = $user?->isManager() ?? false;

        $weekStart = $this->resolveWeekStart($request->query('week'));

        $employeesQuery = Employee::where('shop_id', $shop->id)->active();
        if (! $isManager) {
            // 一般員工只看自己
            $employeesQuery->where('user_id', $user->id);
        }
        $employees = $employeesQuery
            ->orderByDesc('skill_score')
            ->get(['id', 'name', 'level', 'skill_score']);

        $templates = ShiftTemplate::where('shop_id', $shop->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'start_time', 'end_time', 'days_of_week_bitmask']);

        $availabilities = EmployeeAvailability::whereIn(
            'employee_id', $employees->pluck('id')
        )
            ->where('week_start_date', $weekStart->toDateString())
            ->get()
            ->groupBy('employee_id');

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->addDays($i);
            $days[] = [
                'date' => $d->toDateString(),
                'day_of_week' => $d->dayOfWeek,
                'label' => $d->locale('zh_TW')->isoFormat('M/D ddd'),
            ];
        }

        $matrix = $employees->map(function ($emp) use ($availabilities, $templates, $days) {
            $empAvail = $availabilities->get($emp->id, collect());
            $byKey = $empAvail->keyBy(fn ($a) => "{$a->day_of_week}-{$a->shift_template_id}");

            $cells = [];
            foreach ($days as $day) {
                foreach ($templates as $tpl) {
                    $key = "{$day['day_of_week']}-{$tpl->id}";
                    $a = $byKey->get($key);
                    $cells[] = [
                        'date' => $day['date'],
                        'day_of_week' => $day['day_of_week'],
                        'shift_template_id' => $tpl->id,
                        'availability' => $a?->availability,
                        'note' => $a?->note,
                    ];
                }
            }

            return [
                'employee_id' => $emp->id,
                'employee_name' => $emp->name,
                'user_id' => $emp->user_id, // 給前端判斷「填自己 vs 代填別人」
                'skill_score' => $emp->skill_score,
                'level' => $emp->level,
                'submitted' => $empAvail->isNotEmpty(),
                'cells' => $cells,
            ];
        });

        return response()->json([
            'week_start' => $weekStart->toDateString(),
            'is_manager' => $isManager,
            'days' => $days,
            'templates' => $templates->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'start_time' => substr($t->start_time, 0, 5),
                'end_time' => substr($t->end_time, 0, 5),
            ]),
            'matrix' => $matrix,
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'week_start_date' => 'required|date',
            'entries' => 'required|array',
            'entries.*.day_of_week' => 'required|integer|min:0|max:6',
            'entries.*.shift_template_id' => 'required|exists:shift_templates,id',
            'entries.*.availability' => 'required|in:available,unavailable,maybe',
            'entries.*.note' => 'nullable|string|max:200',
        ]);

        $user = Auth::user();
        $employee = Employee::find($data['employee_id']);

        if (! $employee) {
            return response()->json(['error' => '員工不存在'], 404);
        }

        // 權限檢查：店長可代填任何員工；一般員工只能填自己
        $isManager = $user->isManager();
        $isSelf = $employee->user_id === $user->id;

        if (! $isManager && ! $isSelf) {
            return response()->json(['error' => '無權編輯他人的可上時段'], 403);
        }

        // source 自動判斷
        $source = ($isManager && ! $isSelf) ? 'manager_proxy' : 'employee';

        $weekStart = CarbonImmutable::parse($data['week_start_date'])
            ->startOfWeek(CarbonImmutable::MONDAY)
            ->toDateString();

        // 截止日檢查：員工不能在截止後改下個月的可上時段（店長可代填）
        if (! $isManager) {
            $shop = Auth::user()?->resolveCurrentShop();
            $rules = $shop?->settings_json ?? [];
            $cutoffDay = (int) ($rules['availability_cutoff_day'] ?? 25);
            $cutoffTime = $rules['availability_cutoff_time'] ?? '12:00';

            $weekStartCarbon = CarbonImmutable::parse($weekStart);
            $weekMonth = $weekStartCarbon->month;
            $weekYear = $weekStartCarbon->year;
            $now = CarbonImmutable::now();
            $currentMonth = $now->month;
            $currentYear = $now->year;

            // 只有當週次屬於下個月（或更後）且過了截止 → 擋下
            $weekIsFutureMonth = ($weekYear > $currentYear)
                || ($weekYear === $currentYear && $weekMonth > $currentMonth);

            if ($weekIsFutureMonth) {
                $deadline = $now->startOfMonth()
                    ->setDay(min($cutoffDay, $now->daysInMonth))
                    ->setTimeFromTimeString($cutoffTime.':00');
                if ($now->isAfter($deadline)) {
                    return response()->json([
                        'error' => "已過截止時間（每月 {$cutoffDay} 號 {$cutoffTime}），請聯絡店長代填",
                        'cutoff_passed' => true,
                    ], 403);
                }
            }
        }

        // 若非店長，檢查改 unavailable 的時段是否已被排班 → 需店長同意才能改
        if (! $isManager) {
            $blockedSlots = [];
            foreach ($data['entries'] as $entry) {
                if ($entry['availability'] !== 'unavailable') {
                    continue;
                }
                $date = CarbonImmutable::parse($weekStart)->addDays($entry['day_of_week'])->toDateString();
                $isScheduled = ScheduleEntry::where('employee_id', $data['employee_id'])
                    ->where('shift_template_id', $entry['shift_template_id'])
                    ->where('date', $date)
                    ->exists();
                if ($isScheduled) {
                    $blockedSlots[] = $date.' (時段 #'.$entry['shift_template_id'].')';
                }
            }
            if (! empty($blockedSlots)) {
                return response()->json([
                    'error' => '以下時段已被排班，請聯絡店長協調：'.implode('、', $blockedSlots),
                    'requires_manager_approval' => true,
                    'blocked_slots' => $blockedSlots,
                ], 409);
            }
        }

        // 收集變動 (僅 log 真的有改的)
        $changes = [];

        DB::transaction(function () use ($data, $weekStart, $source, &$changes) {
            foreach ($data['entries'] as $entry) {
                $existing = EmployeeAvailability::where([
                    'employee_id' => $data['employee_id'],
                    'week_start_date' => $weekStart,
                    'day_of_week' => $entry['day_of_week'],
                    'shift_template_id' => $entry['shift_template_id'],
                ])->first();

                $beforeAvail = $existing?->availability;
                $beforeNote = $existing?->note;

                EmployeeAvailability::updateOrCreate(
                    [
                        'employee_id' => $data['employee_id'],
                        'week_start_date' => $weekStart,
                        'day_of_week' => $entry['day_of_week'],
                        'shift_template_id' => $entry['shift_template_id'],
                    ],
                    [
                        'availability' => $entry['availability'],
                        'note' => $entry['note'] ?? null,
                        'submitted_at' => now(),
                        'source' => $source,
                    ],
                );

                if ($beforeAvail !== $entry['availability'] || $beforeNote !== ($entry['note'] ?? null)) {
                    $changes[] = [
                        'day_of_week' => $entry['day_of_week'],
                        'shift_template_id' => $entry['shift_template_id'],
                        'before' => ['availability' => $beforeAvail, 'note' => $beforeNote],
                        'after' => ['availability' => $entry['availability'], 'note' => $entry['note'] ?? null],
                    ];
                }
            }
        });

        // 寫 audit log（以 employee 為 entity）
        if (! empty($changes)) {
            AuditService::log(
                action: 'availability.update',
                entity: $employee,
                before: ['week_start' => $weekStart, 'change_count' => count($changes)],
                after: ['source' => $source, 'changes' => $changes],
                shopId: $employee->shop_id,
            );
        }

        return response()->json([
            'message' => 'Submitted',
            'changes_count' => count($changes),
            'source' => $source,
        ]);
    }

    /**
     * 取得員工的「預設可上時段」（員工固定型，跨週適用）
     */
    public function getDefaults(Request $request, Employee $employee): JsonResponse
    {
        $user = $request->user();
        if (! $user->isManager() && $employee->user_id !== $user->id) {
            return response()->json(['error' => '無權檢視'], 403);
        }

        $rows = \App\Models\EmployeeDefaultAvailability::where('employee_id', $employee->id)->get();
        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'day_of_week' => $r->day_of_week,
                'shift_template_id' => $r->shift_template_id,
                'availability' => $r->availability,
            ])->values(),
        ]);
    }

    /**
     * 儲存員工預設可上時段（整批 upsert）
     */
    public function saveDefaults(Request $request, Employee $employee): JsonResponse
    {
        $user = $request->user();
        if (! $user->isManager() && $employee->user_id !== $user->id) {
            return response()->json(['error' => '無權編輯'], 403);
        }

        $data = $request->validate([
            'entries' => 'required|array',
            'entries.*.day_of_week' => 'required|integer|min:0|max:6',
            'entries.*.shift_template_id' => 'required|integer|exists:shift_templates,id',
            'entries.*.availability' => 'required|in:available,unavailable,maybe',
        ]);

        DB::transaction(function () use ($employee, $data) {
            // 刪舊建新（資料量小，這樣最簡單）
            \App\Models\EmployeeDefaultAvailability::where('employee_id', $employee->id)->delete();
            foreach ($data['entries'] as $row) {
                \App\Models\EmployeeDefaultAvailability::create([
                    'employee_id' => $employee->id,
                    'day_of_week' => $row['day_of_week'],
                    'shift_template_id' => $row['shift_template_id'],
                    'availability' => $row['availability'],
                ]);
            }
        });

        return response()->json(['message' => '已儲存預設時段']);
    }

    /**
     * 把員工的預設時段套用到指定週（會覆蓋現有設定）
     */
    public function applyDefaults(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'week_start_date' => 'required|date',
        ]);

        $employee = Employee::find($data['employee_id']);
        if (! $employee) {
            return response()->json(['error' => '員工不存在'], 404);
        }
        if (! $user->isManager() && $employee->user_id !== $user->id) {
            return response()->json(['error' => '無權套用'], 403);
        }

        $weekStart = CarbonImmutable::parse($data['week_start_date'])->startOfWeek(CarbonImmutable::MONDAY);
        $defaults = \App\Models\EmployeeDefaultAvailability::where('employee_id', $employee->id)->get();

        if ($defaults->isEmpty()) {
            return response()->json(['error' => '此員工尚未設定預設時段'], 422);
        }

        DB::transaction(function () use ($employee, $weekStart, $defaults, $user) {
            // 清掉本週現有
            EmployeeAvailability::where('employee_id', $employee->id)
                ->where('week_start_date', $weekStart->toDateString())
                ->delete();
            // 套用 defaults
            foreach ($defaults as $d) {
                EmployeeAvailability::create([
                    'employee_id' => $employee->id,
                    'week_start_date' => $weekStart->toDateString(),
                    'day_of_week' => $d->day_of_week,
                    'shift_template_id' => $d->shift_template_id,
                    'availability' => $d->availability,
                    'source' => $user->isManager() ? 'manager_proxy' : 'employee',
                    'submitted_at' => now(),
                ]);
            }
        });

        return response()->json(['message' => "已套用 {$defaults->count()} 筆預設時段"]);
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
