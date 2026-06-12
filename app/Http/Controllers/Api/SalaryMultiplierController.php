<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopSalaryMultiplier;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SalaryMultiplierController extends Controller
{
    public function index(): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        $rows = $shop->salaryMultipliers()->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->serialize($r))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user->isManager()) return response()->json(['error' => '只有店長以上可設定薪資倍率'], 403);

        $shop = $user->resolveCurrentShop();
        $data = $request->validate([
            'label' => 'required|string|max:100',
            'multiplier' => 'required|numeric|min:0|max:99',
            'condition_type' => 'required|in:weekday_ot,rest_day_ot,holiday,night,custom',
            'condition_json' => 'nullable|array',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $row = $shop->salaryMultipliers()->create([
            'label' => $data['label'],
            'multiplier' => $data['multiplier'],
            'condition_type' => $data['condition_type'],
            'condition_json' => $data['condition_json'] ?? null,
            'sort_order' => $data['sort_order'] ?? ($shop->salaryMultipliers()->max('sort_order') + 10),
            'is_active' => $data['is_active'] ?? true,
        ]);

        AuditService::log('salary_multiplier.create', $row, null, $row->toArray(), $shop->id);

        return response()->json(['data' => $this->serialize($row)], 201);
    }

    public function update(Request $request, ShopSalaryMultiplier $multiplier): JsonResponse
    {
        $user = Auth::user();
        if (! $user->isManager()) return response()->json(['error' => '只有店長以上可設定薪資倍率'], 403);

        $shop = $user->resolveCurrentShop();
        if ($multiplier->shop_id !== $shop->id) return response()->json(['error' => '無權'], 403);

        $data = $request->validate([
            'label' => 'sometimes|string|max:100',
            'multiplier' => 'sometimes|numeric|min:0|max:99',
            'condition_type' => 'sometimes|in:weekday_ot,rest_day_ot,holiday,night,custom',
            'condition_json' => 'nullable|array',
            'sort_order' => 'sometimes|integer',
            'is_active' => 'sometimes|boolean',
        ]);

        $before = $multiplier->toArray();
        $multiplier->update($data);
        AuditService::log('salary_multiplier.update', $multiplier, $before, $multiplier->toArray(), $shop->id);

        return response()->json(['data' => $this->serialize($multiplier->fresh())]);
    }

    public function destroy(ShopSalaryMultiplier $multiplier): JsonResponse
    {
        $user = Auth::user();
        if (! $user->isManager()) return response()->json(['error' => '只有店長以上可設定薪資倍率'], 403);

        $shop = $user->resolveCurrentShop();
        if ($multiplier->shop_id !== $shop->id) return response()->json(['error' => '無權'], 403);

        $before = $multiplier->toArray();
        $multiplier->delete();
        AuditService::log('salary_multiplier.delete', $multiplier, $before, null, $shop->id);

        return response()->json(['message' => 'deleted']);
    }

    private function serialize(ShopSalaryMultiplier $r): array
    {
        return [
            'id' => $r->id,
            'label' => $r->label,
            'multiplier' => (float) $r->multiplier,
            'condition_type' => $r->condition_type,
            'condition_type_label' => match ($r->condition_type) {
                'weekday_ot' => '平日加班',
                'rest_day_ot' => '休息日加班',
                'holiday' => '國定假日',
                'night' => '夜間時段',
                'custom' => '自訂',
                default => $r->condition_type,
            },
            'condition_json' => $r->condition_json,
            'sort_order' => $r->sort_order,
            'is_active' => $r->is_active,
        ];
    }
}
