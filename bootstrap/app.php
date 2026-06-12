<?php

use App\Exceptions\QuotaExceededException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // 不需 CSRF 的端點:
        //  - api/line/webhook:由 LINE 平台呼叫,改用 X-Line-Signature 驗證
        //  - api/liff/session:LIFF 首次交換 id_token,前端還沒拿到 XSRF cookie
        $middleware->validateCsrfTokens(except: [
            'api/line/webhook',
            'api/liff/session',
        ]);

        // Feature 模組 + 權限菜單守門員,掛在 routes 上,不再分散在 controller
        $middleware->alias([
            'feature' => \App\Http\Middleware\RequireFeature::class,
            'permission' => \App\Http\Middleware\RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // SaaS 配額超出 → 402 Payment Required(用於前端引導升級)
        $exceptions->render(function (QuotaExceededException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json($e->toArray(), 402);
            }
        });
    })->create();
