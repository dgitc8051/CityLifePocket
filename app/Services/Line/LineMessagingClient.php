<?php

namespace App\Services\Line;

use App\Models\Shop;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * LINE Messaging API client(per shop)。
 *
 * 用法:
 *   $client = new LineMessagingClient($shop);
 *   $client->push($lineUserId, [['type' => 'text', 'text' => 'hi']]);
 *   $client->multicast([$u1, $u2], $messages);
 *
 * 不在這裡組訊息內容,只負責 HTTP。文案組裝是 NotificationDispatcher 的事。
 */
class LineMessagingClient
{
    private const BASE = 'https://api.line.me/v2/bot';

    public function __construct(private Shop $shop)
    {
        if (! $shop->hasLineConfigured()) {
            throw new RuntimeException("Shop #{$shop->id} 尚未設定 LINE Messaging API");
        }
    }

    /** 推訊息給單一用戶 */
    public function push(string $toLineUserId, array $messages): Response
    {
        return $this->post('/message/push', [
            'to' => $toLineUserId,
            'messages' => array_slice($messages, 0, 5),
        ]);
    }

    /** 一次推給多人(最多 500)。 */
    public function multicast(array $toLineUserIds, array $messages): Response
    {
        return $this->post('/message/multicast', [
            'to' => array_slice($toLineUserIds, 0, 500),
            'messages' => array_slice($messages, 0, 5),
        ]);
    }

    /** Reply 用 reply_token(webhook 收到時帶) */
    public function reply(string $replyToken, array $messages): Response
    {
        return $this->post('/message/reply', [
            'replyToken' => $replyToken,
            'messages' => array_slice($messages, 0, 5),
        ]);
    }

    /** 拿單一用戶 profile(用於 LIFF 之外的 webhook 流程) */
    public function getProfile(string $lineUserId): ?array
    {
        $res = Http::withToken($this->accessToken())
            ->get(self::BASE.'/profile/'.$lineUserId);
        if (! $res->successful()) {
            Log::warning('LINE getProfile failed', ['user' => $lineUserId, 'body' => $res->body()]);
            return null;
        }
        return $res->json();
    }

    private function post(string $path, array $payload): Response
    {
        return Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->retry(2, 500, fn ($e) => $this->shouldRetry($e))
            ->post(self::BASE.$path, $payload);
    }

    private function accessToken(): string
    {
        // accessor 已自動解密
        return $this->shop->line_messaging_access_token_encrypted;
    }

    private function shouldRetry($exception): bool
    {
        // 5xx / network 才重試;4xx(rate limit / invalid token)由 job-level 處理
        $code = method_exists($exception, 'response') && $exception->response()
            ? $exception->response()->status() : 0;
        return $code === 0 || $code >= 500;
    }
}
