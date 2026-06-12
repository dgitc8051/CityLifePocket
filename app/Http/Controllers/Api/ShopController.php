<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\AuditService;
use App\Services\LineCredentialResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShopController extends Controller
{
    public function show(): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }
        $shop->loadMissing('owner:id,name');

        return response()->json([
            'data' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'timezone' => $shop->timezone,
                'line_channel_id' => $shop->line_channel_id,
                'settings_json' => $shop->settings_json ?? [],
                'clock_in_lat' => $shop->clock_in_lat,
                'clock_in_lng' => $shop->clock_in_lng,
                'clock_in_radius_m' => $shop->clock_in_radius_m,
                'features' => $shop->features(),
                'owner' => $shop->owner ? ['id' => $shop->owner->id, 'name' => $shop->owner->name] : null,
                'line' => LineCredentialResolver::publicStatus($shop),
            ],
        ]);
    }

    /**
     * 更新單一功能開關（settings_json.features.<key>）
     */
    public function updateFeatures(\Illuminate\Http\Request $request): JsonResponse
    {
        $user = Auth::user();
        $shop = $user?->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);
        if (! $user->canWrite('settings')) {
            return response()->json(['error' => '無權編輯功能開關'], 403);
        }

        $data = $request->validate([
            'features' => 'required|array',
            'features.*' => 'boolean',
        ]);

        $settings = $shop->settings_json ?? [];
        $features = $settings['features'] ?? [];
        // 只接受已知的 key，避免亂塞
        foreach ($data['features'] as $key => $value) {
            if (array_key_exists($key, \App\Models\Shop::FEATURE_DEFAULTS)) {
                $features[$key] = (bool) $value;
            }
        }
        $settings['features'] = $features;

        $before = $shop->toArray();
        $shop->settings_json = $settings;
        $shop->save();
        AuditService::log('shop.update_features', $shop, $before, $shop->toArray(), $shop->id);

        return response()->json(['data' => ['features' => $shop->features()]]);
    }

    public function update(Request $request): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'timezone' => 'sometimes|required|string|max:64',
            'settings_json' => 'nullable|array',
            'clock_in_lat' => 'nullable|numeric|between:-90,90',
            'clock_in_lng' => 'nullable|numeric|between:-180,180',
            'clock_in_radius_m' => 'nullable|integer|min:0|max:10000',
        ]);

        $before = $shop->toArray();
        $shop->update($validated);
        AuditService::log('shop.update', $shop, $before, $shop->toArray());

        return $this->show();
    }

    /**
     * 更新 LINE 設定。secret/access_token 為空字串時保留現值（不清除）。
     * 想要清除請傳 null 或不送該欄位。
     */
    public function updateLine(Request $request): JsonResponse
    {
        $user = Auth::user();
        $shop = $user?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }
        if (! $user->canWrite('settings')) {
            return response()->json(['error' => '無權編輯 LINE 設定'], 403);
        }

        $data = $request->validate([
            'line_channel_id' => 'nullable|string|max:32',
            'line_channel_secret' => 'nullable|string|max:128',
            'line_messaging_access_token' => 'nullable|string|max:512',
            'line_bot_user_id' => 'nullable|string|max:64',
            'line_login_channel_id' => 'nullable|string|max:32',
            'line_login_channel_secret' => 'nullable|string|max:128',
            'line_liff_id' => 'nullable|string|max:32',
        ]);

        $before = LineCredentialResolver::publicStatus($shop);

        // 將「設定 key」轉成 model 欄位名（secret 系列加 _encrypted 後綴）
        $map = [
            'line_channel_id' => 'line_channel_id',
            'line_channel_secret' => 'line_channel_secret_encrypted',
            'line_messaging_access_token' => 'line_messaging_access_token_encrypted',
            'line_bot_user_id' => 'line_bot_user_id',
            'line_login_channel_id' => 'line_login_channel_id',
            'line_login_channel_secret' => 'line_login_channel_secret_encrypted',
            'line_liff_id' => 'line_liff_id',
        ];

        foreach ($map as $reqKey => $modelKey) {
            if (! array_key_exists($reqKey, $data)) continue;
            $val = $data[$reqKey];
            // 空字串視為「不變更」，避免誤清掉
            if ($val === '' || $val === null) continue;
            $shop->{$modelKey} = $val;
        }
        $shop->save();

        AuditService::log('shop.update_line', $shop, ['line_before' => $before], ['line_after' => LineCredentialResolver::publicStatus($shop)]);

        return response()->json(['data' => LineCredentialResolver::publicStatus($shop)]);
    }
}
