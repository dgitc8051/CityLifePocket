<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

class TeamController extends Controller
{
    public function index(): JsonResponse
    {
        $teams = Team::withCount('members')->get();

        return response()->json(['data' => $teams]);
    }

    public function show(Team $team): JsonResponse
    {
        $team->load('members');

        return response()->json(['data' => $team]);
    }
}
