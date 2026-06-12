<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\ShiftTemplate;
use App\Models\Shop;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $templates = ShiftTemplate::where('shop_id', $shop->id)
            ->with('requiredStations:id,name,color')
            ->orderBy('sort_order')
            ->orderBy('start_time')
            ->get()
            ->map(fn ($t) => $this->transform($t));

        return response()->json(['data' => $templates]);
    }

    public function store(Request $request): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $data = $this->validateData($request);
        $stationRequirements = $data['station_requirements'] ?? [];
        unset($data['station_requirements']);

        if ($err = $this->validateMinHeadcountVsStations($data, $stationRequirements)) {
            return response()->json(['error' => $err], 422);
        }

        $template = ShiftTemplate::create([...$data, 'shop_id' => $shop->id]);
        $this->syncStationRequirements($template, $stationRequirements);
        AuditService::log('shift_template.create', $template, null, $template->toArray());

        return response()->json(['data' => $this->transform($template->fresh('requiredStations'))], 201);
    }

    public function show(ShiftTemplate $shiftTemplate): JsonResponse
    {
        return response()->json(['data' => $this->transform($shiftTemplate)]);
    }

    public function update(Request $request, ShiftTemplate $shiftTemplate): JsonResponse
    {
        $data = $this->validateData($request, $shiftTemplate);
        $stationRequirements = $data['station_requirements'] ?? null;
        unset($data['station_requirements']);

        // 用合併後的值驗證
        $mergedMinHc = $data['min_headcount'] ?? $shiftTemplate->min_headcount;
        $mergedReqs = $stationRequirements !== null
            ? $stationRequirements
            : $shiftTemplate->requiredStations()->get()->map(fn ($s) => ['station_id' => $s->id, 'min_count' => $s->pivot->min_count])->all();
        if ($err = $this->validateMinHeadcountVsStations(['min_headcount' => $mergedMinHc], $mergedReqs)) {
            return response()->json(['error' => $err], 422);
        }

        // 撈 before 的站別需求（pivot 不在 toArray() 內）
        $beforeReqs = $shiftTemplate->requiredStations()->get()
            ->map(fn ($s) => ['station_id' => $s->id, 'name' => $s->name, 'min_count' => (int) ($s->pivot->min_count ?? 1)])
            ->values()->toArray();

        $before = $shiftTemplate->toArray();
        $before['station_requirements'] = $beforeReqs;

        $shiftTemplate->update($data);
        if ($stationRequirements !== null) {
            $this->syncStationRequirements($shiftTemplate, $stationRequirements);
        }

        $afterReqs = $shiftTemplate->requiredStations()->get()
            ->map(fn ($s) => ['station_id' => $s->id, 'name' => $s->name, 'min_count' => (int) ($s->pivot->min_count ?? 1)])
            ->values()->toArray();
        $after = $shiftTemplate->toArray();
        $after['station_requirements'] = $afterReqs;

        AuditService::log('shift_template.update', $shiftTemplate, $before, $after);

        return response()->json(['data' => $this->transform($shiftTemplate->fresh('requiredStations'))]);
    }

    /**
     * 驗證最少人數 ≥ 各站別最少人數加總（不然站別需求湊不齊）
     */
    private function validateMinHeadcountVsStations(array $data, array $stationRequirements): ?string
    {
        $minHeadcount = (int) ($data['min_headcount'] ?? 0);
        $stationTotal = 0;
        foreach ($stationRequirements as $req) {
            $stationTotal += max(1, (int) ($req['min_count'] ?? 1));
        }
        if ($stationTotal > $minHeadcount) {
            return "站別需求共 {$stationTotal} 人，但最少人數只有 {$minHeadcount}。請把最少人數調至 {$stationTotal} 以上。";
        }
        return null;
    }

    private function syncStationRequirements(ShiftTemplate $template, array $requirements): void
    {
        $payload = [];
        foreach ($requirements as $row) {
            $sid = (int) ($row['station_id'] ?? 0);
            $min = max(1, (int) ($row['min_count'] ?? 1));
            if ($sid > 0) {
                $payload[$sid] = ['min_count' => $min];
            }
        }
        $template->requiredStations()->sync($payload);
    }

    public function destroy(ShiftTemplate $shiftTemplate): JsonResponse
    {
        $before = $shiftTemplate->toArray();
        $shiftTemplate->update(['is_active' => false]);
        AuditService::log('shift_template.deactivate', $shiftTemplate, $before, $shiftTemplate->toArray());

        return response()->json(['message' => 'Deactivated']);
    }

    private function validateData(Request $request, ?ShiftTemplate $existing = null): array
    {
        $sometimes = $existing ? 'sometimes|required' : 'required';

        return $request->validate([
            'name' => "{$sometimes}|string|max:64",
            'start_time' => "{$sometimes}|date_format:H:i",
            'end_time' => "{$sometimes}|date_format:H:i",
            'days_of_week_bitmask' => 'sometimes|integer|min:0|max:127',
            'required_score' => 'sometimes|integer|min:0|max:1000',
            'min_senior_count' => 'sometimes|integer|min:0|max:50',
            'min_headcount' => 'sometimes|integer|min:0|max:50',
            'max_headcount' => 'nullable|integer|min:0|max:50',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
            'notes' => 'nullable|string|max:255',
            'station_requirements' => 'nullable|array',
            'station_requirements.*.station_id' => 'required_with:station_requirements|integer|exists:stations,id',
            'station_requirements.*.min_count' => 'nullable|integer|min:1|max:50',
        ]);
    }

    private function transform(ShiftTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'start_time' => substr($t->start_time, 0, 5),
            'end_time' => substr($t->end_time, 0, 5),
            'days_of_week_bitmask' => $t->days_of_week_bitmask,
            'days_label' => $this->daysLabel($t->days_of_week_bitmask),
            'required_score' => $t->required_score,
            'min_senior_count' => $t->min_senior_count,
            'min_headcount' => $t->min_headcount,
            'max_headcount' => $t->max_headcount,
            'is_active' => (bool) $t->is_active,
            'sort_order' => $t->sort_order,
            'notes' => $t->notes,
            'station_requirements' => $t->relationLoaded('requiredStations')
                ? $t->requiredStations->map(fn ($s) => [
                    'station_id' => $s->id,
                    'name' => $s->name,
                    'color' => $s->color,
                    'min_count' => (int) ($s->pivot->min_count ?? 1),
                ])->values()
                : [],
        ];
    }

    private function daysLabel(int $bitmask): string
    {
        $labels = ['日', '一', '二', '三', '四', '五', '六'];
        $active = [];
        for ($i = 0; $i < 7; $i++) {
            if ($bitmask & (1 << $i)) {
                $active[] = $labels[$i];
            }
        }
        if (count($active) === 7) {
            return '每天';
        }
        if (count($active) === 5 && ! in_array('日', $active, true) && ! in_array('六', $active, true)) {
            return '週一 ~ 五';
        }
        if (count($active) === 2 && in_array('日', $active, true) && in_array('六', $active, true)) {
            return '六、日';
        }
        return implode('、', $active);
    }
}
