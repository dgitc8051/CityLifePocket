<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\ScheduleEntry;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * LIFF 端的打卡:極簡化 — 員工已綁定 employee,進來就一鍵上 / 下班。
 */
class LiffAttendanceController extends Controller
{
    /** GET /api/liff/attendance/state — 今天我有班嗎?目前打卡狀態? */
    public function state(Request $request): JsonResponse
    {
        $user = $request->user();
        $shop = $user?->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        $emp = $this->myEmployee($user, $shop->id);
        if (! $emp) return response()->json(['error' => 'not_bound'], 422);

        $today = CarbonImmutable::today();
        $entry = ScheduleEntry::query()->withoutShopScope()
            ->where('employee_id', $emp->id)
            ->whereDate('date', $today->toDateString())
            ->with('shiftTemplate:id,name,start_time,end_time')
            ->first();

        $activeRecord = AttendanceRecord::query()->withoutShopScope()
            ->where('employee_id', $emp->id)
            ->whereDate('clocked_in_at', $today->toDateString())
            ->whereNull('clocked_out_at')
            ->latest('clocked_in_at')
            ->first();

        return response()->json([
            'employee' => ['id' => $emp->id, 'name' => $emp->name],
            'shop' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'has_geofence' => $shop->clock_in_lat !== null && $shop->clock_in_radius_m !== null,
                'clock_in_lat' => $shop->clock_in_lat,
                'clock_in_lng' => $shop->clock_in_lng,
                'clock_in_radius_m' => $shop->clock_in_radius_m,
            ],
            'today_entry' => $entry ? [
                'id' => $entry->id,
                'shift_name' => $entry->shiftTemplate?->name,
                'start_time' => $entry->shiftTemplate?->start_time,
                'end_time' => $entry->shiftTemplate?->end_time,
            ] : null,
            'active_record' => $activeRecord ? [
                'id' => $activeRecord->id,
                'clocked_in_at' => $activeRecord->clocked_in_at->toIso8601String(),
                'status' => $activeRecord->status,
            ] : null,
            'action' => $activeRecord ? 'clock_out' : 'clock_in',
        ]);
    }

    /** POST /api/liff/attendance/punch {lat,lng} — 一鍵上下班 */
    public function punch(Request $request): JsonResponse
    {
        $user = $request->user();
        $shop = $user?->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        $emp = $this->myEmployee($user, $shop->id);
        if (! $emp) return response()->json(['error' => 'not_bound'], 422);

        $data = $request->validate([
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
        ]);

        $today = CarbonImmutable::today();
        $now = CarbonImmutable::now();

        $locationOk = $this->verifyLocation($shop, $data['lat'] ?? null, $data['lng'] ?? null);
        if ($shop->clock_in_lat && $shop->clock_in_radius_m && ! $locationOk) {
            return response()->json([
                'error' => '不在打卡範圍內(geofence)',
                'distance_too_far' => true,
            ], 403);
        }

        $active = AttendanceRecord::query()->withoutShopScope()
            ->where('employee_id', $emp->id)
            ->whereDate('clocked_in_at', $today->toDateString())
            ->whereNull('clocked_out_at')
            ->latest('clocked_in_at')
            ->first();

        if ($active) {
            // 下班
            $active->update([
                'clocked_out_at' => $now,
                'clock_out_lat' => $data['lat'] ?? null,
                'clock_out_lng' => $data['lng'] ?? null,
            ]);
            AuditService::log('liff.clock_out', $active, null, $active->toArray(), $shop->id);

            return response()->json([
                'action' => 'clock_out',
                'record_id' => $active->id,
                'clocked_out_at' => $active->clocked_out_at->toIso8601String(),
            ]);
        }

        // 上班:抓今天的 schedule_entry(若有)
        $entry = ScheduleEntry::query()->withoutShopScope()
            ->where('employee_id', $emp->id)
            ->whereDate('date', $today->toDateString())
            ->whereNotIn('id', AttendanceRecord::withoutShopScope()->whereNotNull('schedule_entry_id')->pluck('schedule_entry_id'))
            ->with('shiftTemplate:id,start_time,end_time')
            ->first();

        $status = $entry ? 'on_time' : 'present_unscheduled';
        $late = 0;
        if ($entry && $entry->shiftTemplate) {
            $expected = CarbonImmutable::parse($entry->date->toDateString().' '.$entry->shiftTemplate->start_time);
            $diff = (int) $now->diffInMinutes($expected, false);
            if ($diff > 5) $status = 'early';
            elseif ($diff < -5) {
                $status = 'late';
                $late = abs($diff);
            }
        }

        $record = AttendanceRecord::create([
            'employee_id' => $emp->id,
            'schedule_entry_id' => $entry?->id,
            'clocked_in_at' => $now,
            'clock_in_lat' => $data['lat'] ?? null,
            'clock_in_lng' => $data['lng'] ?? null,
            'location_verified' => $locationOk,
            'late_minutes' => $late,
            'status' => $status,
        ]);
        AuditService::log('liff.clock_in', $record, null, $record->toArray(), $shop->id);

        return response()->json([
            'action' => 'clock_in',
            'record_id' => $record->id,
            'clocked_in_at' => $record->clocked_in_at->toIso8601String(),
            'status' => $record->status,
            'late_minutes' => $record->late_minutes,
        ], 201);
    }

    private function myEmployee($user, int $shopId): ?Employee
    {
        return Employee::query()->withoutShopScope()
            ->where('shop_id', $shopId)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('line_user_id', $user->line_user_id);
            })
            ->where('status', '!=', 'terminated')
            ->first();
    }

    private function verifyLocation($shop, ?float $lat, ?float $lng): bool
    {
        if (! $shop->clock_in_lat || ! $shop->clock_in_lng || ! $shop->clock_in_radius_m) return true;
        if ($lat === null || $lng === null) return false;
        $R = 6371000.0;
        $dLat = deg2rad($lat - (float) $shop->clock_in_lat);
        $dLng = deg2rad($lng - (float) $shop->clock_in_lng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad((float) $shop->clock_in_lat)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;
        $dist = $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $dist <= $shop->clock_in_radius_m;
    }
}
