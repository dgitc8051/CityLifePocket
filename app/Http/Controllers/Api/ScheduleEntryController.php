<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\Schedule;
use App\Models\ScheduleEntry;
use App\Models\Shop;
use App\Services\AuditService;
use App\Services\ScheduleValidator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class ScheduleEntryController extends Controller
{
    public function store(Request $request, ScheduleValidator $validator): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'shift_template_id' => 'required|exists:shift_templates,id',
            'date' => 'required|date',
            'force' => 'sometimes|boolean',
        ]);

        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $user = $request->user();
        $force = ($data['force'] ?? false) && $user->isManager();

        // 規則檢查
        $check = $validator->validateEntry(
            $data['employee_id'],
            $data['shift_template_id'],
            $data['date'],
        );
        $blocks = collect($check['errors']);

        if ($blocks->isNotEmpty() && ! $force) {
            return response()->json([
                'error' => $blocks->first()['msg'],
                'errors' => $check['errors'],
                'warnings' => $check['warnings'],
                'can_force' => $user->isManager(),
            ], 422);
        }

        $date = CarbonImmutable::parse($data['date']);
        $weekStart = $date->startOfWeek(CarbonImmutable::MONDAY)->toDateString();

        // 並發鎖（從 beautyTwo 借鑒）
        $lockKey = "sched_entry:{$shop->id}:{$data['employee_id']}:{$data['shift_template_id']}:{$data['date']}";
        $lock = Cache::lock($lockKey, 10);
        try {
            $lock->block(3);
        } catch (LockTimeoutException) {
            return response()->json(['error' => '系統忙碌，請稍後'], 503);
        }

        try {
            $schedule = Schedule::firstOrCreate(
                ['shop_id' => $shop->id, 'week_start_date' => $weekStart],
                ['status' => 'draft', 'created_by_user_id' => $shop->owner_user_id],
            );

            // 重複檢查
            $exists = ScheduleEntry::where('schedule_id', $schedule->id)
                ->where('employee_id', $data['employee_id'])
                ->where('shift_template_id', $data['shift_template_id'])
                ->where('date', $data['date'])
                ->exists();

            if ($exists) {
                return response()->json(['error' => '此員工已被排入此時段'], 409);
            }

            $entry = ScheduleEntry::create([
                'schedule_id' => $schedule->id,
                'employee_id' => $data['employee_id'],
                'shift_template_id' => $data['shift_template_id'],
                'date' => $data['date'],
                'status' => 'scheduled',
            ]);

            AuditService::log('schedule_entry.create', $entry, null, $entry->toArray(), $shop->id);

            return response()->json([
                'data' => [
                    'id' => $entry->id,
                    'employee_id' => $entry->employee_id,
                    'shift_template_id' => $entry->shift_template_id,
                    'date' => $entry->date->toDateString(),
                    'status' => $entry->status,
                ],
            ], 201);
        } finally {
            $lock->release();
        }
    }

    public function destroy(ScheduleEntry $entry): JsonResponse
    {
        $before = $entry->toArray();
        $entry->delete();
        AuditService::log('schedule_entry.delete', $entry, $before, null);

        return response()->json(['message' => 'Deleted']);
    }
}
