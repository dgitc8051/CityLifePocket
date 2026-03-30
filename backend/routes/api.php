<?php

use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\OncallController;
use App\Http\Controllers\Api\TeamController;
use Illuminate\Support\Facades\Route;

// ─── Public (no auth) ─── //
Route::post('/login', [AuthController::class, 'login']);

// QR Code scan -> get asset info for report form
Route::get('/assets/{qr_code}/report', [AssetController::class, 'report']);

// Create incident (public report form)
Route::post('/incidents', [IncidentController::class, 'store']);

// ─── Protected (auth required) ─── //
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Incidents
    Route::get('/incidents', [IncidentController::class, 'index']);
    Route::get('/incidents/{incident}', [IncidentController::class, 'show']);
    Route::patch('/incidents/{incident}', [IncidentController::class, 'update']);
    Route::post('/incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge']);
    Route::post('/incidents/{incident}/arrive', [IncidentController::class, 'arrive']);
    Route::post('/incidents/{incident}/resolve', [IncidentController::class, 'resolve']);
    Route::post('/incidents/{incident}/escalate', [IncidentController::class, 'escalate']);

    // Assets
    Route::get('/assets', [AssetController::class, 'index']);
    Route::post('/assets', [AssetController::class, 'store']);
    Route::get('/assets/{asset}', [AssetController::class, 'show']);
    Route::patch('/assets/{asset}', [AssetController::class, 'update']);

    // Teams
    Route::get('/teams', [TeamController::class, 'index']);
    Route::get('/teams/{team}', [TeamController::class, 'show']);

    // On-call
    Route::get('/oncall/current', [OncallController::class, 'current']);
    Route::get('/oncall/schedules', [OncallController::class, 'index']);
    Route::post('/oncall/schedules', [OncallController::class, 'store']);
});
