<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\Shop;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StationController extends Controller
{
    private function requireSettingsWrite(): ?JsonResponse
    {
        $user = Auth::user();
        if (! $user || ! $user->canWrite('settings')) {
            return response()->json(['error' => '無權編輯站別設定'], 403);
        }
        return null;
    }

    public function index(): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $stations = Station::where('shop_id', $shop->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->withCount('employees')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'color' => $s->color,
                'sort_order' => $s->sort_order,
                'is_active' => $s->is_active,
                'employee_count' => $s->employees_count,
            ]);

        return response()->json(['data' => $stations]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($guard = $this->requireSettingsWrite()) return $guard;
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:32', Rule::unique('stations')->where('shop_id', $shop->id)],
            'color' => 'nullable|string|max:16',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'is_active' => 'nullable|boolean',
        ]);

        $data['shop_id'] = $shop->id;
        $data['sort_order'] = $data['sort_order'] ?? Station::where('shop_id', $shop->id)->max('sort_order') + 10;

        $station = Station::create($data);
        AuditService::log('station.create', $station, null, $station->toArray(), $shop->id);

        return response()->json(['data' => $station], 201);
    }

    public function update(Request $request, Station $station): JsonResponse
    {
        if ($guard = $this->requireSettingsWrite()) return $guard;
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop || $station->shop_id !== $shop->id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:32', Rule::unique('stations')->where('shop_id', $shop->id)->ignore($station->id)],
            'color' => 'nullable|string|max:16',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'is_active' => 'nullable|boolean',
        ]);

        $before = $station->toArray();
        $station->update($data);
        AuditService::log('station.update', $station, $before, $station->fresh()->toArray(), $shop->id);

        return response()->json(['data' => $station->fresh()]);
    }

    public function destroy(Station $station): JsonResponse
    {
        if ($guard = $this->requireSettingsWrite()) return $guard;
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop || $station->shop_id !== $shop->id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $before = $station->toArray();
        $station->delete();
        AuditService::log('station.delete', $station, $before, null, $shop->id);

        return response()->json(['message' => 'deleted']);
    }
}
