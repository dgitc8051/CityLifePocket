<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LIFF 端的「會話入口」:
 *   - 前端在 LIFF 內呼叫 `liff.getIDToken()` 拿 id_token
 *   - 把 id_token POST 過來,後端向 LINE 驗證 token,拿到 line_user_id
 *   - 找對應 Employee → 建/取 User → 登入(session)
 *   - 之後 LIFF 內的所有 API 用一般 sanctum session 就行
 */
class LiffController extends Controller
{
    private const VERIFY_URL = 'https://api.line.me/oauth2/v2.1/verify';

    /** POST /api/liff/session {liff_id, id_token} */
    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'liff_id' => 'required|string|max:128',
            'id_token' => 'required|string',
        ]);

        $shop = Shop::query()->withoutShopScope()
            ->where('line_liff_id', $data['liff_id'])
            ->first();
        if (! $shop) {
            return response()->json(['error' => 'liff_not_found'], 404);
        }
        if (! $shop->hasLineLoginConfigured()) {
            return response()->json(['error' => 'login_not_configured'], 422);
        }

        // 向 LINE 驗證 id_token,要傳 client_id (= LINE Login channel id)
        $verify = Http::asForm()->post(self::VERIFY_URL, [
            'id_token' => $data['id_token'],
            'client_id' => $shop->line_login_channel_id,
        ]);

        if (! $verify->successful()) {
            Log::warning('LIFF id_token verify failed', ['body' => $verify->body()]);
            return response()->json(['error' => 'invalid_id_token'], 401);
        }

        $payload = $verify->json();
        $lineUserId = $payload['sub'] ?? null;
        $displayName = $payload['name'] ?? 'LINE 用戶';
        $picture = $payload['picture'] ?? null;
        if (! $lineUserId) {
            return response()->json(['error' => 'no_subject'], 401);
        }

        // 找 / 建 User
        $user = User::where('line_user_id', $lineUserId)->first()
            ?? User::create([
                'name' => $displayName,
                'email' => 'line_'.$lineUserId.'@line.placeholder',
                'password' => bcrypt(\Illuminate\Support\Str::random(40)),
                'role' => 'staff',
                'line_user_id' => $lineUserId,
                'avatar_url' => $picture,
                'organization_id' => $shop->organization_id,
                'current_shop_id' => $shop->id,
            ]);

        // 更新基本資料 / 修正 org
        $user->name = $displayName;
        if ($picture) $user->avatar_url = $picture;
        if (! $user->current_shop_id) $user->current_shop_id = $shop->id;
        if (! $user->organization_id) $user->organization_id = $shop->organization_id;
        $user->save();

        // 找對應 Employee(已綁定)
        $employee = Employee::query()->withoutShopScope()
            ->where('shop_id', $shop->id)
            ->where('line_user_id', $lineUserId)
            ->first();

        Auth::login($user, true);
        $request->session()->regenerate();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar_url' => $user->avatar_url,
                'line_user_id' => $user->line_user_id,
            ],
            'shop' => [
                'id' => $shop->id,
                'name' => $shop->name,
            ],
            'employee' => $employee ? [
                'id' => $employee->id,
                'name' => $employee->name,
                'phone' => $employee->phone,
            ] : null,
            'needs_binding' => $employee === null,
        ]);
    }

    /** GET /api/liff/me — 已登入 session 的 LIFF 端拿目前狀態 */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) return response()->json(['user' => null], 401);

        $shop = $user->resolveCurrentShop();
        $employee = $shop ? Employee::query()
            ->withoutShopScope()
            ->where('shop_id', $shop->id)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('line_user_id', $user->line_user_id);
            })
            ->first() : null;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar_url' => $user->avatar_url,
            ],
            'shop' => $shop ? ['id' => $shop->id, 'name' => $shop->name] : null,
            'employee' => $employee ? [
                'id' => $employee->id,
                'name' => $employee->name,
                'level' => $employee->level,
                'phone' => $employee->phone,
            ] : null,
            'needs_binding' => $employee === null,
        ]);
    }
}
