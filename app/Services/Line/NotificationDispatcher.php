<?php

namespace App\Services\Line;

use App\Jobs\SendLineNotification;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LineNotification;
use App\Models\Schedule;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 統一進入點:任何「我想推 LINE 給用戶」的程式碼都呼叫這裡的 dispatch* 方法。
 *
 * 設計:
 *  - 每一種事件對應一個 template_key + idempotency_key 規則
 *  - 同 idempotency_key 已存在的 row 直接跳過(防重)
 *  - 寫 LineNotification row 後 dispatch SendLineNotification job(支援延遲推送)
 *  - 文案組裝寫在這層,client 只負責 HTTP
 *
 * 文案皆使用 Flex Message 或純文字 quickReply 組合,避免依賴 LINE template type。
 */
class NotificationDispatcher
{
    /* ------------------------ 對員工 ------------------------ */

    /** 班表已發布 → 通知該班表內所有員工 */
    public function dispatchSchedulePublished(Schedule $schedule): int
    {
        $sent = 0;
        $weekLabel = $schedule->week_start_date->format('Y/m/d');

        $employees = $schedule->entries()
            ->withoutShopScope()
            ->with('employee:id,name,line_user_id,shop_id')
            ->get()
            ->pluck('employee')
            ->filter()
            ->unique('id')
            ->filter(fn ($e) => ! empty($e->line_user_id));

        foreach ($employees as $emp) {
            $key = "schedule.published:{$schedule->id}:{$emp->id}:v{$schedule->version}";
            $messages = [[
                'type' => 'text',
                'text' => "📅 {$weekLabel} 那週班表已發布,請至 LIFF 查看詳細班表。",
            ]];

            if ($this->enqueue($schedule->shop_id, $emp, 'schedule.published', $key, $messages, [
                'schedule_id' => $schedule->id,
                'week' => $weekLabel,
            ])) {
                $sent++;
            }
        }

        return $sent;
    }

    /** 請假申請被審核(approve/reject) */
    public function dispatchLeaveReviewed(LeaveRequest $leave): bool
    {
        $emp = $leave->employee;
        if (! $emp || ! $emp->line_user_id) return false;

        $statusLabel = match ($leave->status) {
            'approved' => '✅ 已核准',
            'rejected' => '❌ 已拒絕',
            default => '🔔 狀態更新',
        };
        $dateRange = $leave->start_datetime->format('m/d H:i')
            .' – '.$leave->end_datetime->format('m/d H:i');

        $reviewNote = $leave->review_note ? "\n備註:{$leave->review_note}" : '';

        $key = "leave.reviewed:{$leave->id}:{$leave->status}";
        $messages = [[
            'type' => 'text',
            'text' => "{$statusLabel}\n你的請假申請:{$dateRange}{$reviewNote}",
        ]];

        return $this->enqueue($emp->shop_id, $emp, 'leave.reviewed', $key, $messages, [
            'leave_id' => $leave->id,
            'status' => $leave->status,
        ]);
    }

    /** 班前 N 分鐘提醒打卡(由 cron 觸發) */
    public function dispatchClockInReminder(Employee $emp, int $entryId, CarbonImmutable $startsAt): bool
    {
        if (! $emp->line_user_id) return false;

        $minutesUntil = (int) max(0, $startsAt->diffInMinutes(CarbonImmutable::now()));
        $hhmm = $startsAt->format('H:i');

        $key = "clockin.reminder:{$entryId}:".$startsAt->format('YmdHi');
        $messages = [[
            'type' => 'text',
            'text' => "⏰ 你 {$hhmm} 的班還有 {$minutesUntil} 分鐘要開始,別忘了打卡!",
        ]];

        return $this->enqueue($emp->shop_id, $emp, 'clockin.reminder', $key, $messages, [
            'entry_id' => $entryId,
            'starts_at' => $startsAt->toIso8601String(),
        ], $startsAt->subMinutes(15));
    }

    /* ------------------------ 換班市場 ------------------------ */

    /** 廣播代班請求給某個候選人 */
    public function dispatchCoverageRequested(?\App\Models\Shop $shop, Employee $candidate, \App\Models\ShiftCoverageRequest $request, string $text): bool
    {
        if (! $shop || ! $candidate->line_user_id) return false;
        $key = "coverage.requested:{$request->id}:{$candidate->id}";
        return $this->enqueue($shop->id, $candidate, 'coverage.requested', $key, [
            ['type' => 'text', 'text' => $text],
        ], ['coverage_request_id' => $request->id]);
    }

    /** 通知 requester 有人出價了 */
    public function dispatchCoverageOffered(?\App\Models\Shop $shop, Employee $requester, \App\Models\ShiftCoverageRequest $request, \App\Models\ShiftCoverageOffer $offer, string $text): bool
    {
        if (! $shop || ! $requester->line_user_id) return false;
        $key = "coverage.offered:{$request->id}:{$offer->id}";
        return $this->enqueue($shop->id, $requester, 'coverage.offered', $key, [
            ['type' => 'text', 'text' => $text],
        ], [
            'coverage_request_id' => $request->id,
            'offer_id' => $offer->id,
        ]);
    }

    /** 接受 / 拒絕後通知雙方 */
    public function dispatchCoverageAccepted(?\App\Models\Shop $shop, ?Employee $recipient, \App\Models\ShiftCoverageRequest $request, \App\Models\ShiftCoverageOffer $offer, string $text): bool
    {
        if (! $shop || ! $recipient || ! $recipient->line_user_id) return false;
        $key = "coverage.accepted:{$request->id}:{$offer->id}:{$recipient->id}";
        return $this->enqueue($shop->id, $recipient, 'coverage.accepted', $key, [
            ['type' => 'text', 'text' => $text],
        ], [
            'coverage_request_id' => $request->id,
            'offer_id' => $offer->id,
        ]);
    }

    /* ------------------------ 對店長 ------------------------ */

    /** 員工遲到 / 未到 → 通知店長 */
    public function dispatchLateWarningToManagers(Shop $shop, Employee $absent, CarbonImmutable $expectedAt): int
    {
        $managers = $shop->employees()
            ->whereIn('system_role', ['owner', 'manager', 'sub_manager'])
            ->whereNotNull('line_user_id')
            ->where('id', '!=', $absent->id)
            ->get();

        if ($managers->isEmpty()) return 0;

        $hhmm = $expectedAt->format('H:i');
        $sent = 0;

        foreach ($managers as $m) {
            $key = "attendance.late_warning:{$absent->id}:".$expectedAt->format('YmdHi').":{$m->id}";
            $messages = [[
                'type' => 'text',
                'text' => "⚠️ {$absent->name} 預定 {$hhmm} 上班,目前還沒打卡。請即時聯絡。",
            ]];

            if ($this->enqueue($shop->id, $m, 'attendance.late_warning', $key, $messages, [
                'absent_employee_id' => $absent->id,
                'expected_at' => $expectedAt->toIso8601String(),
            ])) {
                $sent++;
            }
        }

        return $sent;
    }

    /* ------------------------ 核心 enqueue ------------------------ */

    private function enqueue(
        ?int $shopId,
        Employee $emp,
        string $templateKey,
        string $idempotencyKey,
        array $messages,
        array $payload,
        ?CarbonImmutable $scheduledAt = null,
    ): bool {
        try {
            $notification = DB::transaction(function () use ($shopId, $emp, $templateKey, $idempotencyKey, $messages, $payload, $scheduledAt) {
                // 防重:同 idempotency_key 已存在直接跳過
                $existing = LineNotification::withoutShopScope()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) return null;

                return LineNotification::withoutShopScope()->create([
                    'shop_id' => $shopId,
                    'employee_id' => $emp->id,
                    'user_id' => $emp->user_id,
                    'type' => $templateKey,
                    'direction' => 'out',
                    'template_key' => $templateKey,
                    'idempotency_key' => $idempotencyKey,
                    'payload_json' => [
                        'to_line_user_id' => $emp->line_user_id,
                        'messages' => $messages,
                        'context' => $payload,
                    ],
                    'status' => 'queued',
                    'scheduled_at' => $scheduledAt?->toDateTimeString(),
                ]);
            });

            if (! $notification) return false;

            // 派工(scheduled_at 為未來時間 → delay)
            $job = SendLineNotification::dispatch($notification->id);
            if ($scheduledAt && $scheduledAt->isFuture()) {
                $job->delay($scheduledAt);
            }
            return true;
        } catch (\Throwable $e) {
            Log::error('NotificationDispatcher.enqueue failed', [
                'template' => $templateKey,
                'idempotency' => $idempotencyKey,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
