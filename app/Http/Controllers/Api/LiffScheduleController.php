<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ScheduleEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LIFF 員工端:看自己的班表。
 */
class LiffScheduleController extends Controller
{
    /** GET /api/liff/schedule?weeks=2 — 接下來 N 週的我的班 */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $shop = $user?->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        $emp = Employee::query()->withoutShopScope()
            ->where('shop_id', $shop->id)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('line_user_id', $user->line_user_id);
            })
            ->first();
        if (! $emp) return response()->json(['error' => 'not_bound'], 422);

        $weeks = (int) min(8, max(1, $request->query('weeks', 2)));
        $from = CarbonImmutable::today()->startOfDay();
        $to = $from->addWeeks($weeks)->endOfDay();

        $entries = ScheduleEntry::query()->withoutShopScope()
            ->where('employee_id', $emp->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with('shiftTemplate:id,name,start_time,end_time')
            ->orderBy('date')
            ->get();

        $byDate = $entries->groupBy(fn ($e) => $e->date->toDateString())
            ->map(fn ($group, $date) => [
                'date' => $date,
                'dow' => CarbonImmutable::parse($date)->dayOfWeek,
                'entries' => $group->map(fn ($e) => [
                    'id' => $e->id,
                    'shift_name' => $e->shiftTemplate?->name,
                    'start_time' => substr($e->shiftTemplate?->start_time ?? '', 0, 5),
                    'end_time' => substr($e->shiftTemplate?->end_time ?? '', 0, 5),
                ])->values(),
            ])->values();

        return response()->json([
            'employee' => ['id' => $emp->id, 'name' => $emp->name],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $byDate,
        ]);
    }
}
