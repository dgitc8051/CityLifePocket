<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ShiftCoverageOffer;
use App\Models\ShiftCoverageRequest;
use App\Services\ShiftCoverageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LIFF 員工端換班市場:
 *   GET  /api/liff/coverage/feed
 *   POST /api/liff/coverage/{coverageRequest}/offer
 *   POST /api/liff/coverage/offer/{offer}/withdraw
 */
class LiffCoverageController extends Controller
{
    public function __construct(private ShiftCoverageService $coverage) {}

    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        $shop = $user?->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        $emp = $this->myEmployee($user, $shop->id);
        if (! $emp) return response()->json(['error' => 'not_bound'], 422);

        return response()->json([
            'data' => $this->coverage->feedForEmployee($emp),
        ]);
    }

    public function offer(Request $request, ShiftCoverageRequest $coverageRequest): JsonResponse
    {
        $user = $request->user();
        $shop = $user?->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        $emp = $this->myEmployee($user, $shop->id);
        if (! $emp) return response()->json(['error' => 'not_bound'], 422);

        $data = $request->validate([
            'message' => 'nullable|string|max:255',
        ]);

        try {
            $offer = $this->coverage->offer($coverageRequest, $emp, $data['message'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [
            'id' => $offer->id,
            'status' => $offer->status,
            'coverage_request_id' => $offer->coverage_request_id,
        ]], 201);
    }

    public function withdraw(Request $request, ShiftCoverageOffer $offer): JsonResponse
    {
        $user = $request->user();
        $shop = $user?->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        $emp = $this->myEmployee($user, $shop->id);
        if (! $emp || $offer->volunteer_employee_id !== $emp->id) {
            return response()->json(['error' => '無權'], 403);
        }

        try {
            $offer = $this->coverage->withdraw($offer);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['id' => $offer->id, 'status' => $offer->status]]);
    }

    private function myEmployee($user, int $shopId): ?Employee
    {
        return Employee::query()->withoutShopScope()
            ->where('shop_id', $shopId)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('line_user_id', $user->line_user_id);
            })
            ->first();
    }
}
