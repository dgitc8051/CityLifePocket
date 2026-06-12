<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\BusinessHour;
use App\Models\Shop;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessHoursController extends Controller
{
    private const DAY_LABELS = ['週日', '週一', '週二', '週三', '週四', '週五', '週六'];

    public function index(): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $hours = BusinessHour::where('shop_id', $shop->id)
            ->orderBy('day_of_week')
            ->get();

        // 確保 7 天都有資料（沒有的補上 null）
        $byDow = $hours->keyBy('day_of_week');
        $data = [];
        for ($dow = 0; $dow <= 6; $dow++) {
            $existing = $byDow->get($dow);
            $data[] = [
                'day_of_week' => $dow,
                'day_label' => self::DAY_LABELS[$dow],
                'is_closed' => $existing?->is_closed ?? true,
                'open_time' => $existing?->open_time ? substr($existing->open_time, 0, 5) : null,
                'close_time' => $existing?->close_time ? substr($existing->close_time, 0, 5) : null,
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $data = $request->validate([
            'hours' => 'required|array|size:7',
            'hours.*.day_of_week' => 'required|integer|min:0|max:6',
            'hours.*.is_closed' => 'required|boolean',
            'hours.*.open_time' => 'nullable|required_if:hours.*.is_closed,false|date_format:H:i',
            'hours.*.close_time' => 'nullable|required_if:hours.*.is_closed,false|date_format:H:i',
        ]);

        $before = BusinessHour::where('shop_id', $shop->id)->get()->toArray();

        DB::transaction(function () use ($data, $shop) {
            foreach ($data['hours'] as $row) {
                BusinessHour::updateOrCreate(
                    ['shop_id' => $shop->id, 'day_of_week' => $row['day_of_week']],
                    [
                        'is_closed' => $row['is_closed'],
                        'open_time' => $row['is_closed'] ? null : ($row['open_time'].':00'),
                        'close_time' => $row['is_closed'] ? null : ($row['close_time'].':00'),
                    ],
                );
            }
        });

        AuditService::log(
            'business_hours.bulk_update',
            $shop,
            ['hours' => $before],
            ['hours' => $data['hours']],
            $shop->id,
        );

        return $this->index();
    }
}
