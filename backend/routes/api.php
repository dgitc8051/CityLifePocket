<?php

use App\Http\Controllers\Api\ParkingSessionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// ─── Parking Sessions (no auth required for MVP) ─── //
Route::prefix('parking-sessions')->group(function () {
    Route::get('/', [ParkingSessionController::class, 'index']);
    Route::post('/', [ParkingSessionController::class, 'store']);
    Route::get('/active', [ParkingSessionController::class, 'active']);
    Route::patch('/{token}/complete', [ParkingSessionController::class, 'complete']);
});
