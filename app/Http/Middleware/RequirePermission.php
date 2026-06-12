<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 把 menu permission 守在路由上,而不是分散在 controller 裡用 isManager()。
 *
 * 用法:
 *   Route::middleware('permission:schedule,r')->get(...);          // 需要讀權限
 *   Route::middleware('permission:schedule,rw')->post(...);        // 需要寫權限
 *   Route::middleware('permission:schedule')->put(...);            // 預設 rw
 *
 * 觸發行為:
 *   無權 → 403 + JSON {error: permission_denied, menu: ..., level: ...}
 *
 * admin 短路:User::hasPermission 已經處理。
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $menu, string $level = 'rw'): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        if (! $user->hasPermission($menu, $level)) {
            return response()->json([
                'error' => 'permission_denied',
                'menu' => $menu,
                'level' => $level,
            ], 403);
        }

        return $next($request);
    }
}
