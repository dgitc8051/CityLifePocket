<?php

namespace App\Jobs;

use App\Models\LineNotification;
use App\Models\Shop;
use App\Services\Line\LineMessagingClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 送一筆 LineNotification 到 LINE。
 *
 * - 失敗會 retry 3 次(2/5/15 分鐘)
 * - shop 沒設 LINE → 標記 failed,不重試
 * - 4xx 視為永久失敗,不重試
 */
class SendLineNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [120, 300, 900];

    public function __construct(public int $notificationId) {}

    public function handle(): void
    {
        $n = LineNotification::withoutShopScope()->find($this->notificationId);
        if (! $n) {
            Log::warning('SendLineNotification: notification not found', ['id' => $this->notificationId]);
            return;
        }

        if ($n->status === 'sent') return; // 已成功送過

        $shop = $n->shop_id ? Shop::query()->withoutShopScope()->find($n->shop_id) : null;
        if (! $shop || ! $shop->hasLineConfigured()) {
            $this->markFailed($n, 'shop_line_not_configured');
            return;
        }

        $to = data_get($n->payload_json, 'to_line_user_id');
        $messages = data_get($n->payload_json, 'messages', []);
        if (! $to || empty($messages)) {
            $this->markFailed($n, 'invalid_payload');
            return;
        }

        try {
            $client = new LineMessagingClient($shop);
            $res = $client->push($to, $messages);

            if ($res->successful()) {
                $n->status = 'sent';
                $n->sent_at = now();
                $n->line_message_id = $res->header('X-Line-Request-Id');
                $n->error_message = null;
                $n->save();
                return;
            }

            $status = $res->status();
            $body = $res->body();

            // 4xx → 永久失敗
            if ($status >= 400 && $status < 500) {
                $this->markFailed($n, "http_{$status}:{$body}");
                return;
            }

            // 5xx → 拋例外讓 Laravel queue retry
            throw new \RuntimeException("LINE API {$status}: {$body}");
        } catch (\Throwable $e) {
            $n->retry_count = $n->retry_count + 1;
            $n->error_message = mb_substr($e->getMessage(), 0, 250);
            $n->save();
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $n = LineNotification::withoutShopScope()->find($this->notificationId);
        if ($n) $this->markFailed($n, $e->getMessage());
    }

    private function markFailed(LineNotification $n, string $reason): void
    {
        $n->status = 'failed';
        $n->error_message = mb_substr($reason, 0, 250);
        $n->save();
    }
}
