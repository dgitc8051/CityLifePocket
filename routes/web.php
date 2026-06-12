<?php

use App\Http\Controllers\Auth\LineAuthController;
use Illuminate\Support\Facades\Route;

// LINE OAuth Login（需要 session、不在 SPA 範圍內）
Route::get('/auth/line/redirect', [LineAuthController::class, 'redirect'])->name('line.redirect');
Route::get('/auth/line/callback', [LineAuthController::class, 'callback'])->name('line.callback');

// SPA catch-all
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api|auth/line).*$');
