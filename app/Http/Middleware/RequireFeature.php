<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 把某 feature 變成「整個 vertical」級別的開關。
 *
 * 用法:在路由群組或單一路由套
 *   Route::middleware(['auth:sanctum', 'feature:stations'])->group(...)
 *
 * Feature 關閉時:
 *  - 整個 route 群組回 403 + JSON {error: feature_disabled, feature: <key>}
 *  - 前端拿到 403 後可以由全域 axios interceptor 統一 redirect 或 toast
 *
 * 邏輯:看 Auth::user()->resolveCurrentShop()->feature($key)
 *  - 沒登入 / 沒店 → 一律拒絕(這條 middleware 應該掛在 auth:sanctum 後)
 *  - admin 也吃這條 — admin 跨店時看的還是 current_shop 的 feature 狀態
 */
class RequireFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $shop = Auth::user()?->resolveCurrentShop();

        if (! $shop) {
            return response()->json([
                'error' => 'feature_unavailable',
                'feature' => $feature,
                'reason' => 'no_shop',
            ], 403);
        }

        if (! $shop->feature($feature)) {
            return response()->json([
                'error' => 'feature_disabled',
                'feature' => $feature,
                'shop_id' => $shop->id,
            ], 403);
        }

        return $next($request);
    }
}
