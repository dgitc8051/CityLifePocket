<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LineNotification;
use App\Models\Shop;
use App\Services\Line\LineMessagingClient;
use App\Services\LineCredentialResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 接收 LINE Messaging API webhook(用戶 follow / message / postback)。
 *
 * 路由:POST /api/line/webhook(無 auth,用 signature 驗證)
 *
 * destination 欄位 = bot 的 user id,用來決定要進哪家店的 LINE 設定。
 *
 * 支援:
 *  - follow:用戶加好友 → 回歡迎訊息 + 引導綁定
 *  - unfollow:用戶封鎖 → 標記 employee.line_user_id=null
 *  - message text:文字訊息 → 簡單關鍵字導引
 *  - postback:LIFF / Flex button → 之後 case-by-case 處理
 */
class LineWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $signature = $request->header('X-Line-Signature', '');

        $destination = $request->input('destination');
        $shop = $destination ? LineCredentialResolver::findShopByBotUserId($destination) : null;

        if (! $shop || ! $shop->hasLineConfigured()) {
            Log::warning('LineWebhook: unknown destination', ['destination' => $destination]);
            return response()->json(['ok' => false, 'error' => 'unknown_destination'], 200);
        }

        if (! $this->verifySignature($body, $signature, $shop->line_channel_secret_encrypted)) {
            Log::warning('LineWebhook: signature mismatch', ['shop' => $shop->id]);
            // LINE 文件建議回 200 避免被持續 retry,但記錄下來
            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 200);
        }

        $events = $request->input('events', []);
        foreach ($events as $event) {
            try {
                $this->routeEvent($shop, $event);
            } catch (\Throwable $e) {
                Log::error('LineWebhook event handler error', [
                    'shop' => $shop->id,
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['ok' => true]);
    }

    private function verifySignature(string $body, string $signature, ?string $channelSecret): bool
    {
        if (! $channelSecret || ! $signature) return false;
        $expected = base64_encode(hash_hmac('sha256', $body, $channelSecret, true));
        return hash_equals($expected, $signature);
    }

    private function routeEvent(Shop $shop, array $event): void
    {
        $type = $event['type'] ?? null;
        $sourceUserId = data_get($event, 'source.userId');

        // 記下 inbound 事件供稽核
        LineNotification::withoutShopScope()->create([
            'shop_id' => $shop->id,
            'employee_id' => $this->lookupEmployee($shop, $sourceUserId)?->id,
            'user_id' => null,
            'type' => 'webhook.'.$type,
            'direction' => 'in',
            'template_key' => 'webhook.'.$type,
            'idempotency_key' => $this->eventIdempotencyKey($shop, $event),
            'payload_json' => $event,
            'status' => 'sent', // inbound 不需要送
        ]);

        match ($type) {
            'follow'   => $this->onFollow($shop, $event),
            'unfollow' => $this->onUnfollow($shop, $event),
            'message'  => $this->onMessage($shop, $event),
            default    => null,
        };
    }

    private function onFollow(Shop $shop, array $event): void
    {
        $replyToken = $event['replyToken'] ?? null;
        if (! $replyToken) return;

        $welcome = [[
            'type' => 'text',
            'text' => "歡迎加入 {$shop->name} 的排班系統 LINE 通知!\n請至員工頁面綁定電話號碼以接收班表、請假、打卡提醒。",
        ]];

        try {
            (new LineMessagingClient($shop))->reply($replyToken, $welcome);
        } catch (\Throwable $e) {
            Log::warning('LineWebhook follow reply failed', ['error' => $e->getMessage()]);
        }
    }

    private function onUnfollow(Shop $shop, array $event): void
    {
        $userId = data_get($event, 'source.userId');
        if (! $userId) return;

        // 解綁 employee.line_user_id(避免未來繼續推給已封鎖用戶)
        Employee::query()
            ->withoutShopScope()
            ->where('shop_id', $shop->id)
            ->where('line_user_id', $userId)
            ->update(['line_user_id' => null]);
    }

    private function onMessage(Shop $shop, array $event): void
    {
        $replyToken = $event['replyToken'] ?? null;
        $text = data_get($event, 'message.text', '');
        if (! $replyToken || ! $text) return;

        $reply = $this->keywordReply($shop, $text);
        if (! $reply) return;

        try {
            (new LineMessagingClient($shop))->reply($replyToken, [['type' => 'text', 'text' => $reply]]);
        } catch (\Throwable $e) {
            Log::warning('LineWebhook message reply failed', ['error' => $e->getMessage()]);
        }
    }

    private function keywordReply(Shop $shop, string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        $liff = $shop->line_liff_id;

        return match (true) {
            in_array($t, ['打卡', 'clockin', 'clock-in', 'punch'], true) =>
                $liff ? "請點開 LIFF 打卡:https://liff.line.me/{$liff}/clockin" : '尚未啟用 LIFF,請聯絡店長。',
            in_array($t, ['班表', 'schedule', '查班'], true) =>
                $liff ? "我的班表:https://liff.line.me/{$liff}/schedule" : '尚未啟用 LIFF。',
            in_array($t, ['請假', 'leave'], true) =>
                $liff ? "請假申請:https://liff.line.me/{$liff}/leave" : '尚未啟用 LIFF。',
            in_array($t, ['help', '幫助', '?', '？'], true) =>
                "可用關鍵字:打卡 / 班表 / 請假",
            default => null,
        };
    }

    private function lookupEmployee(Shop $shop, ?string $lineUserId): ?Employee
    {
        if (! $lineUserId) return null;
        return Employee::query()
            ->withoutShopScope()
            ->where('shop_id', $shop->id)
            ->where('line_user_id', $lineUserId)
            ->first();
    }

    private function eventIdempotencyKey(Shop $shop, array $event): string
    {
        // LINE 不一定有 event id,用 webhookEventId 或組合 hash 替代
        $id = data_get($event, 'webhookEventId')
            ?? hash('sha256', json_encode([$shop->id, $event['type'] ?? '?', $event['timestamp'] ?? 0, data_get($event, 'source.userId')]));
        return 'webhook:'.$id;
    }
}
