<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\Holiday;
use App\Models\Shop;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    public function index(): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $holidays = Holiday::where('shop_id', $shop->id)
            ->orderBy('date')
            ->get()
            ->map(fn ($h) => $this->transform($h));

        return response()->json(['data' => $holidays]);
    }

    public function store(Request $request): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $data = $request->validate([
            'date' => 'required|date',
            'type' => 'required|in:closed,special',
            'note' => 'nullable|string|max:255',
            'is_recurring' => 'sometimes|boolean',
            'recurrence_rule' => 'nullable|string|max:64',
        ]);

        $holiday = Holiday::create([...$data, 'shop_id' => $shop->id]);
        AuditService::log('holiday.create', $holiday, null, $holiday->toArray());

        return response()->json(['data' => $this->transform($holiday)], 201);
    }

    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $data = $request->validate([
            'date' => 'sometimes|required|date',
            'type' => 'sometimes|required|in:closed,special',
            'note' => 'nullable|string|max:255',
            'is_recurring' => 'sometimes|boolean',
            'recurrence_rule' => 'nullable|string|max:64',
        ]);

        $before = $holiday->toArray();
        $holiday->update($data);
        AuditService::log('holiday.update', $holiday, $before, $holiday->toArray());

        return response()->json(['data' => $this->transform($holiday->fresh())]);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $before = $holiday->toArray();
        $holiday->delete();
        AuditService::log('holiday.delete', $holiday, $before, null);

        return response()->json(['message' => 'Deleted']);
    }

    private function transform(Holiday $h): array
    {
        return [
            'id' => $h->id,
            'date' => $h->date->toDateString(),
            'date_label' => $h->date->locale('zh_TW')->isoFormat('M/D (ddd)'),
            'type' => $h->type,
            'type_label' => $h->type === 'closed' ? '公休' : '特殊',
            'note' => $h->note,
            'is_recurring' => (bool) $h->is_recurring,
            'recurrence_rule' => $h->recurrence_rule,
        ];
    }
}
