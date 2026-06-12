<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\ScheduleEntry;
use App\Models\Shop;
use App\Services\AuditService;
use App\Services\SalaryCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AttendanceController extends Controller
{
    /**
     * 今日出勤狀態 dashboard：列出所有今天有班的員工 + 是否打卡
     */
    public function todayStatus(Request $request): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        $today = CarbonImmutable::today();
        $dayStart = $today->startOfDay();
        $dayEnd = $today->endOfDay();

        $entries = ScheduleEntry::query()
            ->whereHas('schedule', fn ($q) => $q->where('shop_id', $shop->id))
            ->whereDate('date', $today->toDateString())
            ->with(['employee:id,name,level', 'shiftTemplate:id,name,start_time,end_time'])
            ->get();

        $records = AttendanceRecord::query()
            ->whereHas('employee', fn ($q) => $q->where('shop_id', $shop->id))
            ->whereBetween('clocked_in_at', [$dayStart, $dayEnd])
            ->with(['employee:id,name', 'scheduleEntry:id,shift_template_id,date'])
            ->orderByDesc('clocked_in_at')
            ->get();

        $clockedEntryIds = $records->pluck('schedule_entry_id')->filter()->unique()->all();

        $scheduled = $entries->map(fn ($e) => [
            'entry_id' => $e->id,
            'employee_id' => $e->employee_id,
            'employee_name' => $e->employee?->name ?? '?',
            'shift_name' => $e->shiftTemplate?->name ?? '?',
            'shift_time' => substr($e->shiftTemplate?->start_time ?? '', 0, 5).'–'.substr($e->shiftTemplate?->end_time ?? '', 0, 5),
            'clocked' => in_array($e->id, $clockedEntryIds, true),
        ])->values();

        $unscheduled = $records->filter(fn ($r) => ! $r->schedule_entry_id)
            ->map(fn ($r) => [
                'record_id' => $r->id,
                'employee_id' => $r->employee_id,
                'employee_name' => $r->employee?->name ?? '?',
                'clocked_in_at' => $r->clocked_in_at?->toIso8601String(),
                'clocked_out_at' => $r->clocked_out_at?->toIso8601String(),
            ])->values();

        $allRecords = $records->map(fn ($r) => $this->serialize($r))->values();

        return response()->json([
            'today' => $today->toDateString(),
            'scheduled' => $scheduled,
            'unscheduled' => $unscheduled,
            'records' => $allRecords,
            'summary' => [
                'scheduled_count' => $scheduled->count(),
                'clocked_count' => $scheduled->where('clocked', true)->count(),
                'unscheduled_count' => $unscheduled->count(),
            ],
        ]);
    }

    /**
     * 卡片網格 UI 的資料源：列出所有員工 + 今日打卡狀態。
     */
    public function cardGrid(): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        $today = CarbonImmutable::today();

        $employees = Employee::where('shop_id', $shop->id)
            ->where('status', 'active')
            ->with('stations:id,name')
            ->orderBy('name')
            ->get();

        $records = AttendanceRecord::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('clocked_in_at', [$today->startOfDay(), $today->endOfDay()])
            ->get()
            ->groupBy('employee_id');

        $cards = $employees->map(function ($e) use ($records) {
            $todayRecords = $records->get($e->id, collect());
            $active = $todayRecords->whereNull('clocked_out_at')->first();
            $latest = $todayRecords->sortByDesc('clocked_in_at')->first();

            $status = match (true) {
                $active !== null => 'on_duty',
                $latest !== null => 'clocked_out',
                default => 'not_clocked_in',
            };

            return [
                'id' => $e->id,
                'name' => $e->name,
                'code' => sprintf('E%04d', $e->id),
                'level' => $e->level,
                'role' => $e->system_role,
                'stations' => $e->stations->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
                'has_pin' => $e->birthday !== null,
                'status' => $status,
                'status_label' => match ($status) {
                    'on_duty' => '已上班',
                    'clocked_out' => '已下班',
                    'not_clocked_in' => '未打卡',
                },
                'active_record_id' => $active?->id,
                'latest_clock_in_at' => $latest?->clocked_in_at?->toIso8601String(),
                'latest_clock_out_at' => $latest?->clocked_out_at?->toIso8601String(),
            ];
        });

        $byDept = $employees->groupBy(fn ($e) => $e->system_role ?? '未分類');
        $departments = $byDept->map(fn ($v, $k) => ['key' => $k, 'label' => $k, 'count' => $v->count()])->values();

        return response()->json([
            'cards' => $cards->values(),
            'departments' => $departments,
            'shop' => [
                'has_geofence' => $shop->clock_in_lat !== null && $shop->clock_in_lng !== null,
                'clock_in_lat' => $shop->clock_in_lat,
                'clock_in_lng' => $shop->clock_in_lng,
                'clock_in_radius_m' => $shop->clock_in_radius_m,
            ],
        ]);
    }

    /**
     * PIN 打卡（員工自助）：輸入生日 MMDD 驗身，自動上 / 下班。
     */
    public function clockWithPin(Request $request): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        $data = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'pin' => 'required|string|max:8',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
        ]);

        $emp = Employee::find($data['employee_id']);
        if (! $emp || $emp->shop_id !== $shop->id) {
            return response()->json(['error' => '員工不存在'], 404);
        }

        $expectedPin = $emp->getDefaultAttendancePin();
        if (! $expectedPin) {
            return response()->json(['error' => '此員工未設定生日，請聯絡店長設定打卡密碼'], 422);
        }
        if (! hash_equals($expectedPin, $data['pin'])) {
            return response()->json(['error' => '密碼錯誤'], 401);
        }

        $locationVerified = $this->verifyLocation($shop, $data['lat'] ?? null, $data['lng'] ?? null);
        if ($shop->clock_in_lat !== null && $shop->clock_in_radius_m && ! $locationVerified) {
            return response()->json([
                'error' => '不在打卡範圍內',
                'detail' => '請至店面或聯絡店長',
            ], 403);
        }

        $active = AttendanceRecord::where('employee_id', $emp->id)
            ->whereDate('clocked_in_at', CarbonImmutable::today()->toDateString())
            ->whereNull('clocked_out_at')
            ->latest('clocked_in_at')
            ->first();

        if ($active) {
            return $this->performClockOut($active, $data['lat'] ?? null, $data['lng'] ?? null, $shop, $emp);
        }
        return $this->performClockIn($emp, $data['lat'] ?? null, $data['lng'] ?? null, $locationVerified, $shop);
    }

    public function clockIn(Request $request): JsonResponse
    {
        $user = Auth::user();
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        $data = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'schedule_entry_id' => 'nullable|exists:schedule_entries,id',
            'note' => 'nullable|string|max:255',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
        ]);

        $emp = Employee::find($data['employee_id']);
        if (! $emp || $emp->shop_id !== $shop->id) {
            return response()->json(['error' => '員工不存在'], 404);
        }
        if (! $user->isManager() && $emp->user_id !== $user->id) {
            return response()->json(['error' => '只能幫自己打卡'], 403);
        }

        if ($data['schedule_entry_id'] ?? null) {
            $exists = AttendanceRecord::where('schedule_entry_id', $data['schedule_entry_id'])->exists();
            if ($exists) {
                return response()->json(['error' => '此班次已打過卡'], 409);
            }
        }

        $locationVerified = $this->verifyLocation($shop, $data['lat'] ?? null, $data['lng'] ?? null);

        return $this->performClockIn(
            $emp,
            $data['lat'] ?? null,
            $data['lng'] ?? null,
            $locationVerified,
            $shop,
            $data['schedule_entry_id'] ?? null,
            $data['note'] ?? null,
        );
    }

    public function clockOut(Request $request, AttendanceRecord $record): JsonResponse
    {
        $user = Auth::user();
        $shop = Auth::user()?->resolveCurrentShop();
        $emp = $record->employee;
        if (! $emp || $emp->shop_id !== $shop?->id) {
            return response()->json(['error' => '紀錄不存在'], 404);
        }
        if (! $user->isManager() && $emp->user_id !== $user->id) {
            return response()->json(['error' => '無權'], 403);
        }
        if ($record->clocked_out_at) {
            return response()->json(['error' => '此班已下班'], 422);
        }

        $data = $request->validate([
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
        ]);

        return $this->performClockOut($record, $data['lat'] ?? null, $data['lng'] ?? null, $shop, $emp);
    }

    public function destroy(Request $request, AttendanceRecord $record): JsonResponse
    {
        $user = Auth::user();
        if (! $user->isManager()) {
            return response()->json(['error' => '只有店長以上可以刪除打卡紀錄'], 403);
        }
        $shop = Auth::user()?->resolveCurrentShop();
        $before = $record->toArray();
        $record->delete();
        AuditService::log('attendance.delete', $record, $before, null, $shop?->id);
        return response()->json(['message' => 'deleted']);
    }

    public function index(Request $request): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'employee_id' => 'nullable|exists:employees,id',
        ]);

        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->startOfDay() : CarbonImmutable::today()->subDays(7)->startOfDay();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->endOfDay() : CarbonImmutable::today()->endOfDay();

        $query = AttendanceRecord::query()
            ->whereHas('employee', fn ($q) => $q->where('shop_id', $shop->id))
            ->whereBetween('clocked_in_at', [$from, $to])
            ->with(['employee:id,name', 'scheduleEntry.shiftTemplate:id,name,start_time,end_time'])
            ->orderByDesc('clocked_in_at');

        if (isset($data['employee_id'])) {
            $query->where('employee_id', $data['employee_id']);
        }

        $records = $query->limit(500)->get();

        return response()->json([
            'data' => $records->map(fn ($r) => $this->serialize($r))->values(),
            'meta' => ['count' => $records->count(), 'from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    /**
     * 員工個人時數表（依倍率拆桶 + 月份分組）
     */
    public function personalHours(Request $request): JsonResponse
    {
        $user = Auth::user();
        $shop = $user?->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        $data = $request->validate([
            'employee_id' => 'nullable|exists:employees,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $emp = null;
        if ($data['employee_id'] ?? null) {
            $emp = Employee::find($data['employee_id']);
            if (! $emp || $emp->shop_id !== $shop->id) {
                return response()->json(['error' => '員工不存在'], 404);
            }
            if (! $user->isManager() && $emp->user_id !== $user->id) {
                return response()->json(['error' => '無權'], 403);
            }
        } else {
            if (! $user->isManager()) {
                $emp = Employee::where('user_id', $user->id)->first();
                if (! $emp) return response()->json(['error' => '無對應員工'], 404);
            } else {
                return response()->json(['error' => '請指定 employee_id'], 422);
            }
        }

        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'])->startOfDay()
            : CarbonImmutable::now()->subMonths(6)->startOfMonth();
        $to = isset($data['to'])
            ? CarbonImmutable::parse($data['to'])->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        $calc = new SalaryCalculator($shop);
        $result = $calc->calculateForEmployee($emp, $from, $to);

        $byMonth = collect($result['records'])->groupBy(fn ($r) => substr($r['date'] ?? '', 0, 7));
        $monthSummaries = [];
        foreach ($byMonth as $month => $recs) {
            if (! $month) continue;
            $monthSummaries[] = [
                'month' => $month,
                'records_count' => count($recs),
                'work_minutes' => array_sum(array_map(fn ($r) => $r['work_minutes'] ?? 0, $recs->all())),
                'ot_detected_minutes' => array_sum(array_map(fn ($r) => $r['ot_detected_minutes'], $recs->all())),
                'ot_approved_minutes' => array_sum(array_map(fn ($r) => $r['ot_approved_minutes'], $recs->all())),
                'records' => $recs->values()->all(),
            ];
        }
        usort($monthSummaries, fn ($a, $b) => strcmp($b['month'], $a['month']));

        return response()->json([
            'employee' => [
                'id' => $emp->id,
                'name' => $emp->name,
                'hourly_wage' => $emp->hourly_wage,
            ],
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => [
                'work_minutes' => $result['work_minutes'],
                'late_minutes' => $result['late_minutes'],
                'ot_detected_minutes' => $result['ot_detected_minutes'],
                'ot_approved_minutes' => $result['ot_approved_minutes'],
            ],
            'buckets' => $result['buckets'],
            'months' => $monthSummaries,
        ]);
    }

    public function approveOvertime(Request $request, AttendanceRecord $record): JsonResponse
    {
        $user = Auth::user();
        if (! $user->isManager()) {
            return response()->json(['error' => '只有店長以上可核可加班'], 403);
        }
        $shop = $user->resolveCurrentShop();
        $emp = $record->employee;
        if (! $emp || $emp->shop_id !== $shop?->id) {
            return response()->json(['error' => '紀錄不存在'], 404);
        }

        $data = $request->validate([
            'approved_minutes' => 'nullable|integer|min:0',
        ]);

        $minutes = $data['approved_minutes'] ?? $record->overtime_minutes_detected;

        $before = $record->toArray();
        $record->overtime_minutes_approved = $minutes;
        $record->overtime_approved_by = $user->id;
        $record->overtime_approved_at = now();
        $record->save();

        AuditService::log('attendance.approve_overtime', $record, $before, $record->toArray(), $shop->id);

        return response()->json(['data' => $this->serialize($record->fresh(['employee', 'scheduleEntry']))]);
    }

    public function rejectOvertime(Request $request, AttendanceRecord $record): JsonResponse
    {
        $user = Auth::user();
        if (! $user->isManager()) {
            return response()->json(['error' => '只有店長以上可拒絕加班'], 403);
        }
        $shop = $user->resolveCurrentShop();
        $emp = $record->employee;
        if (! $emp || $emp->shop_id !== $shop?->id) {
            return response()->json(['error' => '紀錄不存在'], 404);
        }

        $before = $record->toArray();
        $record->overtime_minutes_approved = 0;
        $record->overtime_approved_by = $user->id;
        $record->overtime_approved_at = now();
        $record->save();

        AuditService::log('attendance.reject_overtime', $record, $before, $record->toArray(), $shop->id);

        return response()->json(['data' => $this->serialize($record->fresh(['employee', 'scheduleEntry']))]);
    }

    public function pendingOvertime(): JsonResponse
    {
        $user = Auth::user();
        if (! $user->isManager()) return response()->json(['error' => '無權'], 403);
        $shop = $user->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        $records = AttendanceRecord::query()
            ->whereHas('employee', fn ($q) => $q->where('shop_id', $shop->id))
            ->where('overtime_minutes_detected', '>', 0)
            ->whereNull('overtime_approved_at')
            ->with(['employee:id,name', 'scheduleEntry.shiftTemplate:id,name,start_time,end_time'])
            ->orderByDesc('clocked_in_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $records->map(fn ($r) => $this->serialize($r))->values(),
            'count' => $records->count(),
        ]);
    }

    // ===== private helpers =====

    private function performClockIn(
        Employee $emp,
        ?float $lat,
        ?float $lng,
        bool $locationVerified,
        Shop $shop,
        ?int $scheduleEntryId = null,
        ?string $note = null,
    ): JsonResponse {
        $now = CarbonImmutable::now();
        $status = 'on_time';
        $lateMinutes = 0;

        if (! $scheduleEntryId) {
            $todayEntry = ScheduleEntry::where('employee_id', $emp->id)
                ->whereDate('date', $now->toDateString())
                ->whereNotIn('id', AttendanceRecord::whereNotNull('schedule_entry_id')->pluck('schedule_entry_id'))
                ->with('shiftTemplate:id,start_time,end_time')
                ->first();
            if ($todayEntry) $scheduleEntryId = $todayEntry->id;
        }

        if ($scheduleEntryId) {
            $entry = ScheduleEntry::with('shiftTemplate:id,start_time,end_time')->find($scheduleEntryId);
            if ($entry && $entry->shiftTemplate) {
                $expectedStart = CarbonImmutable::parse($entry->date->toDateString().' '.$entry->shiftTemplate->start_time);
                $diff = (int) $now->diffInMinutes($expectedStart, false);
                if ($diff > 5) $status = 'early';
                elseif ($diff < -5) {
                    $status = 'late';
                    $lateMinutes = abs($diff);
                }
            }
        } else {
            $status = 'present_unscheduled';
        }

        $record = AttendanceRecord::create([
            'employee_id' => $emp->id,
            'schedule_entry_id' => $scheduleEntryId,
            'clocked_in_at' => $now,
            'clock_in_lat' => $lat,
            'clock_in_lng' => $lng,
            'location_verified' => $locationVerified,
            'late_minutes' => $lateMinutes,
            'status' => $status,
            'note' => $note,
        ]);

        AuditService::log('attendance.clock_in', $record, null, $record->toArray(), $shop->id);

        return response()->json([
            'data' => $this->serialize($record->fresh(['employee', 'scheduleEntry'])),
            'action' => 'clock_in',
        ], 201);
    }

    private function performClockOut(AttendanceRecord $record, ?float $lat, ?float $lng, Shop $shop, Employee $emp): JsonResponse
    {
        $now = CarbonImmutable::now();
        $before = $record->toArray();

        $otMinutes = 0;
        if ($record->schedule_entry_id) {
            $entry = ScheduleEntry::with('shiftTemplate:id,start_time,end_time')->find($record->schedule_entry_id);
            if ($entry && $entry->shiftTemplate) {
                $expectedEnd = CarbonImmutable::parse($entry->date->toDateString().' '.$entry->shiftTemplate->end_time);
                if ($now->greaterThan($expectedEnd)) {
                    $otMinutes = (int) $now->diffInMinutes($expectedEnd);
                }
            }
        }

        $record->clocked_out_at = $now;
        $record->clock_out_lat = $lat;
        $record->clock_out_lng = $lng;
        $record->overtime_minutes_detected = $otMinutes;
        // 若店家關閉「加班需核可」流程 → 偵測到加班直接視為已核可
        if ($otMinutes > 0 && ! $shop->feature('ot_approval')) {
            $record->overtime_minutes_approved = $otMinutes;
            $record->overtime_approved_at = $now;
        }
        $record->save();

        AuditService::log('attendance.clock_out', $record, $before, $record->toArray(), $shop->id);

        return response()->json([
            'data' => $this->serialize($record->fresh(['employee', 'scheduleEntry'])),
            'action' => 'clock_out',
        ]);
    }

    private function verifyLocation(Shop $shop, ?float $lat, ?float $lng): bool
    {
        if (! $shop->clock_in_lat || ! $shop->clock_in_lng || ! $shop->clock_in_radius_m) {
            return true;
        }
        if ($lat === null || $lng === null) return false;
        $dist = $this->haversineMeters((float) $shop->clock_in_lat, (float) $shop->clock_in_lng, $lat, $lng);
        return $dist <= $shop->clock_in_radius_m;
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function serialize(AttendanceRecord $r): array
    {
        $hoursWorked = null;
        if ($r->clocked_in_at && $r->clocked_out_at) {
            $hoursWorked = round($r->clocked_out_at->diffInMinutes($r->clocked_in_at) / 60, 1);
        }

        return [
            'id' => $r->id,
            'employee_id' => $r->employee_id,
            'employee_name' => $r->employee?->name ?? '?',
            'schedule_entry_id' => $r->schedule_entry_id,
            'shift_name' => $r->scheduleEntry?->shiftTemplate?->name ?? null,
            'shift_time' => $r->scheduleEntry?->shiftTemplate
                ? substr($r->scheduleEntry->shiftTemplate->start_time, 0, 5).'–'.substr($r->scheduleEntry->shiftTemplate->end_time, 0, 5)
                : null,
            'clocked_in_at' => $r->clocked_in_at?->toIso8601String(),
            'clocked_out_at' => $r->clocked_out_at?->toIso8601String(),
            'hours_worked' => $hoursWorked,
            'late_minutes' => (int) $r->late_minutes,
            'ot_detected_minutes' => (int) $r->overtime_minutes_detected,
            'ot_approved_minutes' => (int) $r->overtime_minutes_approved,
            'ot_pending' => (int) $r->overtime_minutes_detected > 0 && $r->overtime_approved_at === null,
            'location_verified' => (bool) $r->location_verified,
            'status' => $r->status,
            'status_label' => match ($r->status) {
                'on_time' => '準時',
                'late' => '遲到',
                'early' => '提早',
                'no_show' => '未到',
                'present_unscheduled' => '無排班來打卡',
                default => $r->status,
            },
            'note' => $r->note,
            'date' => $r->clocked_in_at?->toDateString(),
        ];
    }
}
