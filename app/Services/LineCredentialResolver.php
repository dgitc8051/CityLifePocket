<?php

namespace App\Services;

use App\Models\Shop;

/**
 * 單一入口取得某家店的 LINE 設定。
 *
 * 未來如果要支援 brand-level 共用 LINE（同品牌多家店共用一個 LINE 官方帳號），
 * 在這層加 fallback 邏輯：shop 沒設就回 brand 的設定。
 *
 * 設計原則：所有跟 LINE 互動的程式碼只透過這個 service 拿 credentials，
 * 不要直接讀 Shop model 的 line_* 欄位。
 */
class LineCredentialResolver
{
    /**
     * @return array{
     *   shop_id:int,
     *   has_messaging:bool,
     *   has_login:bool,
     *   has_liff:bool,
     *   messaging:array{channel_id:?string, channel_secret:?string, access_token:?string, bot_user_id:?string},
     *   login:array{channel_id:?string, channel_secret:?string},
     *   liff:array{liff_id:?string}
     * }
     */
    public static function forShop(Shop $shop): array
    {
        return [
            'shop_id' => $shop->id,
            'has_messaging' => $shop->hasLineConfigured(),
            'has_login' => $shop->hasLineLoginConfigured(),
            'has_liff' => ! empty($shop->line_liff_id),
            'messaging' => [
                'channel_id' => $shop->line_channel_id ?: null,
                'channel_secret' => $shop->line_channel_secret_encrypted ?: null,
                'access_token' => $shop->line_messaging_access_token_encrypted ?: null,
                'bot_user_id' => $shop->line_bot_user_id ?: null,
            ],
            'login' => [
                'channel_id' => $shop->line_login_channel_id ?: null,
                'channel_secret' => $shop->line_login_channel_secret_encrypted ?: null,
            ],
            'liff' => [
                'liff_id' => $shop->line_liff_id ?: null,
            ],
        ];
    }

    /**
     * 給前端用：不含 secret，只顯示「已設 / 未設」狀態
     */
    public static function publicStatus(Shop $shop): array
    {
        return [
            'has_messaging' => $shop->hasLineConfigured(),
            'has_login' => $shop->hasLineLoginConfigured(),
            'has_liff' => ! empty($shop->line_liff_id),
            'messaging_channel_id' => $shop->line_channel_id,
            'login_channel_id' => $shop->line_login_channel_id,
            'liff_id' => $shop->line_liff_id,
            'bot_user_id' => $shop->line_bot_user_id,
            // secrets 不回傳，只回「是否已設」
            'messaging_secret_set' => ! empty($shop->line_channel_secret_encrypted),
            'login_secret_set' => ! empty($shop->line_login_channel_secret_encrypted),
            'access_token_set' => ! empty($shop->line_messaging_access_token_encrypted),
        ];
    }

    /**
     * 透過 channel_id 反查屬於哪個 shop。webhook router 用。
     * 例：LINE 推 webhook 來時，從 destination 欄位的 bot user id 或 channel id 找出 shop。
     */
    public static function findShopByMessagingChannelId(string $channelId): ?Shop
    {
        return Shop::where('line_channel_id', $channelId)->first();
    }

    public static function findShopByBotUserId(string $botUserId): ?Shop
    {
        return Shop::where('line_bot_user_id', $botUserId)->first();
    }
}
