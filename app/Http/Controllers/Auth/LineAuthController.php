<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use App\Services\LineCredentialResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LineAuthController extends Controller
{
    private const AUTHORIZE_URL = 'https://access.line.me/oauth2/v2.1/authorize';
    private const TOKEN_URL = 'https://api.line.me/oauth2/v2.1/token';
    private const PROFILE_URL = 'https://api.line.me/v2/profile';

    /**
     * 1. 用戶按「使用 LINE 登入」→ 這個端點。
     *    產生 state + nonce 存 session、把用戶跳轉到 LINE 授權頁。
     *
     * Hook: 接受 ?shop_id=N 指定品牌底下哪一家店的 LINE channel。
     * 多店 SaaS 化後改成 hostname-based 解析。
     */
    public function redirect(Request $request)
    {
        $shop = $this->resolveShopForLogin($request);
        if (! $shop) {
            return redirect('/login?error=line_no_shop');
        }
        if (! $shop->hasLineLoginConfigured()) {
            return redirect('/login?error=line_not_configured');
        }

        $state = Str::random(40);
        $nonce = Str::random(32);

        session([
            'line_oauth.state' => $state,
            'line_oauth.nonce' => $nonce,
            'line_oauth.shop_id' => $shop->id,
        ]);

        $url = self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $shop->line_login_channel_id,
            'redirect_uri' => $this->callbackUrl(),
            'state' => $state,
            'scope' => 'profile openid',
            'nonce' => $nonce,
        ]);

        return redirect($url);
    }

    /**
     * 2. LINE 跳回來：?code=...&state=...
     *    驗 state → 換 token → 取 profile → 找/建 User → 登入 → 跳首頁
     */
    public function callback(Request $request)
    {
        // 用戶在 LINE 端取消授權
        if ($error = $request->query('error')) {
            return redirect('/login?error=line_canceled&detail='.urlencode($error));
        }

        $code = $request->query('code');
        $state = $request->query('state');

        if (! $code || ! $state) {
            return redirect('/login?error=line_invalid_response');
        }
        if ($state !== session('line_oauth.state')) {
            Log::warning('LINE callback state mismatch', [
                'expected' => session('line_oauth.state'),
                'got' => $state,
            ]);
            return redirect('/login?error=line_state_mismatch');
        }

        $shopId = session('line_oauth.shop_id');
        $shop = Shop::find($shopId);
        if (! $shop || ! $shop->hasLineLoginConfigured()) {
            return redirect('/login?error=line_no_shop');
        }

        // 清掉用過的 state（避免重放）
        session()->forget(['line_oauth.state', 'line_oauth.nonce', 'line_oauth.shop_id']);

        // 換 token
        $tokenResponse = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->callbackUrl(),
            'client_id' => $shop->line_login_channel_id,
            'client_secret' => $shop->line_login_channel_secret_encrypted, // accessor 已自動解密
        ]);

        if (! $tokenResponse->successful()) {
            Log::error('LINE token exchange failed', ['body' => $tokenResponse->body()]);
            return redirect('/login?error=line_token_failed');
        }

        $tokenData = $tokenResponse->json();
        $accessToken = $tokenData['access_token'] ?? null;
        if (! $accessToken) {
            return redirect('/login?error=line_no_access_token');
        }

        // 取 profile
        $profileResponse = Http::withToken($accessToken)->get(self::PROFILE_URL);
        if (! $profileResponse->successful()) {
            Log::error('LINE profile fetch failed', ['body' => $profileResponse->body()]);
            return redirect('/login?error=line_profile_failed');
        }

        $profile = $profileResponse->json();
        $lineUserId = $profile['userId'] ?? null;
        if (! $lineUserId) {
            return redirect('/login?error=line_no_user_id');
        }

        // 找 / 建 User
        $user = User::where('line_user_id', $lineUserId)->first();

        if (! $user) {
            $user = User::create([
                'name' => $profile['displayName'] ?? 'LINE 用戶',
                'email' => 'line_'.$lineUserId.'@line.placeholder',
                'password' => bcrypt(Str::random(40)),
                'role' => 'staff', // 預設員工角色，等店長綁定 employee 才能用
                'line_user_id' => $lineUserId,
                'avatar_url' => $profile['pictureUrl'] ?? null,
                'current_shop_id' => $shop->id,
            ]);
        } else {
            // 每次登入更新名稱/頭像
            $user->name = $profile['displayName'] ?? $user->name;
            $user->avatar_url = $profile['pictureUrl'] ?? $user->avatar_url;
            if (! $user->current_shop_id) {
                $user->current_shop_id = $shop->id;
            }
            $user->save();
        }

        // 建 web session（Sanctum SPA 模式會吃到）
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect('/');
    }

    /**
     * 給前端用：取得目前 shop 的 LINE Login 設定狀態 + 登入 URL。
     */
    public function status(Request $request)
    {
        $shop = $this->resolveShopForLogin($request);
        $enabled = $shop && $shop->hasLineLoginConfigured();

        return response()->json([
            'enabled' => $enabled,
            'redirect_url' => $enabled ? url('/auth/line/redirect') : null,
        ]);
    }

    // -----

    private function callbackUrl(): string
    {
        return rtrim(config('app.url'), '/').'/auth/line/callback';
    }

    /**
     * 解析使用者要登入「哪一家店」的 LINE。
     * 目前單店：用第一家。
     * 未來多店：?shop_id= 或 hostname 解析。
     */
    private function resolveShopForLogin(Request $request): ?Shop
    {
        if ($shopId = $request->query('shop_id')) {
            return Shop::find($shopId);
        }
        // 預設：第一家店
        return Shop::query()->first();
    }
}
