<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OncallSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OncallController extends Controller
{
    /**
     * GET /api/oncall/current
     */
    public function current(): JsonResponse
    {
        $schedules = OncallSchedule::currentlyOnCall()
            ->with(['team', 'user'])
            ->get();

        return response()->json(['data' => $schedules]);
    }

    /**
     * GET /api/oncall/schedules
     */
    public function index(): JsonResponse
    {
        $schedules = OncallSchedule::with(['team', 'user'])
            ->orderByDesc('start_at')
            ->paginate(20);

        return response()->json($schedules);
    }

    /**
     * POST /api/oncall/schedules
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'team_id' => 'required|exists:teams,id',
            'user_id' => 'required|exists:users,id',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
        ]);

        $schedule = OncallSchedule::create($validated);

        return response()->json([
            'data' => $schedule->load(['team', 'user']),
        ], 201);
    }
}
