<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    /**
     * GET /api/assets
     */
    public function index(Request $request): JsonResponse
    {
        $query = Asset::with('team');

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        $assets = $query->orderBy('asset_number')->paginate(20);

        return response()->json($assets);
    }

    /**
     * POST /api/assets
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asset_number' => 'required|string|unique:assets',
            'name' => 'required|string|max:255',
            'type' => 'required|in:equipment,software',
            'category' => 'required|string',
            'location' => 'nullable|string',
            'model' => 'nullable|string',
            'team_id' => 'required|exists:teams,id',
            'installed_at' => 'nullable|date',
            'warranty_expires_at' => 'nullable|date',
        ]);

        $validated['qr_code'] = $validated['asset_number'];

        $asset = Asset::create($validated);

        return response()->json(['data' => $asset->load('team')], 201);
    }

    /**
     * GET /api/assets/{id}
     */
    public function show(Asset $asset): JsonResponse
    {
        $asset->load(['team', 'maintenanceLogs.performer', 'incidents']);

        return response()->json(['data' => $asset]);
    }

    /**
     * PATCH /api/assets/{id}
     */
    public function update(Request $request, Asset $asset): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'location' => 'sometimes|string',
            'model' => 'sometimes|string',
            'team_id' => 'sometimes|exists:teams,id',
            'is_active' => 'sometimes|boolean',
        ]);

        $asset->update($validated);

        return response()->json(['data' => $asset->fresh()->load('team')]);
    }

    /**
     * GET /api/assets/{qr_code}/report
     * Public endpoint: scan QR code to get asset info for report form.
     */
    public function report(string $qrCode): JsonResponse
    {
        $asset = Asset::where('qr_code', $qrCode)
            ->where('is_active', true)
            ->with('team')
            ->first();

        if (!$asset) {
            return response()->json(['message' => '找不到此設備'], 404);
        }

        return response()->json([
            'data' => [
                'id' => $asset->id,
                'asset_number' => $asset->asset_number,
                'name' => $asset->name,
                'type' => $asset->type,
                'category' => $asset->category,
                'location' => $asset->location,
                'model' => $asset->model,
                'team' => $asset->team->name,
                'last_maintained_at' => $asset->last_maintained_at,
            ],
        ]);
    }
}
