<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParkingSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ParkingSessionController extends Controller
{
    /**
     * POST /api/parking-sessions
     * Create a new parking session
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'floor' => 'nullable|string|max:10',
            'zone' => 'nullable|string|max:100',
            'spot_number' => 'nullable|string|max:50',
            'photos' => 'nullable|array',
            'photos.*' => 'string',
            'custom_note' => 'nullable|string|max:500',
            'is_underground' => 'boolean',
        ]);

        $session = ParkingSession::create([
            'session_token' => Str::random(48),
            'lat' => $validated['lat'],
            'lng' => $validated['lng'],
            'accuracy' => $validated['accuracy'] ?? null,
            'floor' => $validated['floor'] ?? null,
            'zone' => $validated['zone'] ?? null,
            'spot_number' => $validated['spot_number'] ?? null,
            'photos_json' => $validated['photos'] ?? null,
            'custom_note' => $validated['custom_note'] ?? null,
            'is_underground' => $validated['is_underground'] ?? false,
            'started_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $session,
        ], 201);
    }

    /**
     * GET /api/parking-sessions/active
     * Get the active parking session by token
     */
    public function active(Request $request)
    {
        $token = $request->query('token');

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Session token is required',
            ], 400);
        }

        $session = ParkingSession::where('session_token', $token)
            ->active()
            ->first();

        if (!$session) {
            return response()->json([
                'success' => true,
                'data' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $session,
        ]);
    }

    /**
     * PATCH /api/parking-sessions/{id}/complete
     * Mark a parking session as completed
     */
    public function complete(Request $request, string $id)
    {
        $session = ParkingSession::where('session_token', $id)
            ->active()
            ->firstOrFail();

        $session->update(['completed_at' => now()]);

        return response()->json([
            'success' => true,
            'data' => $session->fresh(),
        ]);
    }

    /**
     * GET /api/parking-sessions
     * List parking sessions (history)
     */
    public function index(Request $request)
    {
        $token = $request->query('token');
        $limit = min($request->query('limit', 20), 50);

        $query = ParkingSession::completed()
            ->orderByDesc('started_at')
            ->limit($limit);

        // In future with auth, filter by user_id
        // For now, we rely on client-side localStorage

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }
}
