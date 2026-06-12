<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ScheduleEntry;
use App\Models\Shop;
use App\Models\ShiftCoverageOffer;
use App\Models\ShiftCoverageRequest;
use App\Services\Line\NotificationDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 換班市場(Coverage Market)主要邏輯。
 *
 * 一筆 ScheduleEntry 同時最多只有一筆 open coverage_request。
 * Volunteers 同時最多一個 pending offer per request。
 *
 * accept 時做的事:
 *  1. 把 schedule_entry.employee_id 改成 volunteer
 *  2. 該 request fulfilled,其他 pending offer 全 reject
 *  3. 通知 volunteer 與 requester
 */
class ShiftCoverageService
{
    public function __construct(private NotificationDispatcher $notifier) {}

    /**
     * 員工發起代班請求。
     */
    public function open(ScheduleEntry $entry, Employee $requester, ?string $reason = null): ShiftCoverageRequest
    {
        if ($entry->employee_id !== $requester->id) {
            throw new RuntimeException('只能為自己的班次發起代班');
        }

        // 確保沒有已存在的 open request
        $existing = ShiftCoverageRequest::withoutShopScope()
            ->where('schedule_entry_id', $entry->id)
            ->where('status', 'open')
            ->first();
        if ($existing) {
            throw new RuntimeException('此班次已有未完成的代班請求');
        }

        // 預設 expires_at = 班次開始前 2 小時(讓店長有時間人工介入)
        $entryStart = $this->entryStartDateTime($entry);
        $expiresAt = $entryStart?->subHours(2);

        return DB::transaction(function () use ($entry, $requester, $reason, $expiresAt) {
            $request = ShiftCoverageRequest::create([
                'schedule_entry_id' => $entry->id,
                'requester_employee_id' => $requester->id,
                'reason' => $reason,
                'status' => 'open',
                'expires_at' => $expiresAt,
            ]);

            // 廣播 LINE 給合格的潛在代班者(同店 / active / 沒衝突 / 非本人)
            $this->broadcastToEligible($request, $entry, $requester);

            return $request;
        });
    }

    /**
     * 志願者出價(我接這班)。
     */
    public function offer(ShiftCoverageRequest $request, Employee $volunteer, ?string $message = null): ShiftCoverageOffer
    {
        if (! $request->isOpen()) {
            throw new RuntimeException('此代班請求已關閉');
        }
        if ($volunteer->id === $request->requester_employee_id) {
            throw new RuntimeException('不能對自己的請求出價');
        }
        if (! $this->isEligibleVolunteer($volunteer, $request)) {
            throw new RuntimeException('此班次與你既有班次衝突,或你不屬於此店');
        }

        $offer = ShiftCoverageOffer::updateOrCreate(
            [
                'coverage_request_id' => $request->id,
                'volunteer_employee_id' => $volunteer->id,
            ],
            [
                'message' => $message,
                'status' => 'pending',
                'responded_at' => null,
            ]
        );

        // 通知 requester 有人接班
        $this->notifyRequesterOfNewOffer($request, $offer);

        return $offer;
    }

    /**
     * 接受 offer(requester 或 manager 操作)。
     */
    public function accept(ShiftCoverageRequest $request, ShiftCoverageOffer $offer): ShiftCoverageRequest
    {
        if ($offer->coverage_request_id !== $request->id) {
            throw new RuntimeException('offer 不屬於此 request');
        }
        if (! $request->isOpen()) {
            throw new RuntimeException('此代班請求已關閉');
        }
        if ($offer->status !== 'pending') {
            throw new RuntimeException('此 offer 已處理過');
        }

        return DB::transaction(function () use ($request, $offer) {
            $now = now();

            // 1. 把 schedule_entry 的人換成 volunteer
            $entry = ScheduleEntry::withoutShopScope()->findOrFail($request->schedule_entry_id);
            $entry->update(['employee_id' => $offer->volunteer_employee_id]);

            // 2. offer 標記 accepted,其餘 pending offer 自動 reject
            $offer->update(['status' => 'accepted', 'responded_at' => $now]);
            ShiftCoverageOffer::withoutShopScope()
                ->where('coverage_request_id', $request->id)
                ->where('id', '!=', $offer->id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected', 'responded_at' => $now]);

            // 3. request 標 fulfilled
            $request->update([
                'status' => 'fulfilled',
                'accepted_offer_id' => $offer->id,
                'accepted_employee_id' => $offer->volunteer_employee_id,
                'fulfilled_at' => $now,
            ]);

            // 4. 通知 volunteer + requester
            $this->notifyCoverageAccepted($request->fresh(['requester', 'acceptedEmployee']), $offer);

            return $request->fresh(['requester', 'acceptedEmployee', 'offers', 'scheduleEntry']);
        });
    }

    public function cancel(ShiftCoverageRequest $request): ShiftCoverageRequest
    {
        if (! $request->isOpen()) {
            throw new RuntimeException('此代班請求已關閉');
        }
        $request->update(['status' => 'cancelled']);
        return $request->fresh();
    }

    public function withdraw(ShiftCoverageOffer $offer): ShiftCoverageOffer
    {
        if ($offer->status !== 'pending') {
            throw new RuntimeException('此出價已處理過');
        }
        $offer->update(['status' => 'withdrawn', 'responded_at' => now()]);
        return $offer->fresh();
    }

    /**
     * 給某員工的可見 feed(他能看到並出價的 open requests)。
     */
    public function feedForEmployee(Employee $emp, int $limit = 30): array
    {
        $requests = ShiftCoverageRequest::query()
            ->withoutShopScope()
            ->whereHas('requester', fn ($q) => $q->where('shop_id', $emp->shop_id))
            ->where('status', 'open')
            ->where('requester_employee_id', '!=', $emp->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with(['requester:id,name', 'scheduleEntry.shiftTemplate:id,name,start_time,end_time'])
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        return $requests->map(function ($r) use ($emp) {
            $entry = $r->scheduleEntry;
            $existingOffer = ShiftCoverageOffer::withoutShopScope()
                ->where('coverage_request_id', $r->id)
                ->where('volunteer_employee_id', $emp->id)
                ->first();

            return [
                'id' => $r->id,
                'reason' => $r->reason,
                'expires_at' => $r->expires_at?->toIso8601String(),
                'requester' => ['id' => $r->requester?->id, 'name' => $r->requester?->name],
                'date' => $entry?->date->toDateString(),
                'shift_name' => $entry?->shiftTemplate?->name,
                'start_time' => substr($entry?->shiftTemplate?->start_time ?? '', 0, 5),
                'end_time' => substr($entry?->shiftTemplate?->end_time ?? '', 0, 5),
                'has_conflict' => ! $this->isEligibleVolunteer($emp, $r, $entry),
                'my_offer' => $existingOffer ? [
                    'id' => $existingOffer->id,
                    'status' => $existingOffer->status,
                ] : null,
            ];
        })->values()->all();
    }

    /* ---------- private ---------- */

    private function entryStartDateTime(ScheduleEntry $entry): ?CarbonImmutable
    {
        $shift = $entry->shiftTemplate;
        if (! $shift) return null;
        return CarbonImmutable::parse($entry->date->toDateString().' '.$shift->start_time);
    }

    private function isEligibleVolunteer(Employee $volunteer, ShiftCoverageRequest $request, ?ScheduleEntry $entry = null): bool
    {
        $entry ??= $request->scheduleEntry;
        if (! $entry) return false;

        $requester = $request->requester;
        if (! $requester || $volunteer->shop_id !== $requester->shop_id) return false;
        if ($volunteer->status !== 'active') return false;

        // 衝突:同一天有自己的 active entry
        $hasConflict = ScheduleEntry::withoutShopScope()
            ->where('employee_id', $volunteer->id)
            ->whereDate('date', $entry->date->toDateString())
            ->exists();

        return ! $hasConflict;
    }

    private function broadcastToEligible(ShiftCoverageRequest $request, ScheduleEntry $entry, Employee $requester): void
    {
        $candidates = Employee::query()
            ->withoutShopScope()
            ->where('shop_id', $requester->shop_id)
            ->where('status', 'active')
            ->where('id', '!=', $requester->id)
            ->whereNotNull('line_user_id')
            ->get();

        $shop = Shop::query()->withoutShopScope()->find($requester->shop_id);
        $shiftName = $entry->shiftTemplate?->name ?? '?';
        $time = substr($entry->shiftTemplate?->start_time ?? '', 0, 5)
            .'–'.substr($entry->shiftTemplate?->end_time ?? '', 0, 5);
        $date = $entry->date->format('m/d');

        foreach ($candidates as $cand) {
            // 個別檢查衝突
            if (! $this->isEligibleVolunteer($cand, $request, $entry)) continue;

            $this->notifier->dispatchCoverageRequested(
                $shop,
                $cand,
                $request,
                "🆘 {$requester->name} 想找人代 {$date} {$shiftName} ({$time}),你有空嗎?".($request->reason ? "\n原因:{$request->reason}" : ''),
            );
        }
    }

    private function notifyRequesterOfNewOffer(ShiftCoverageRequest $request, ShiftCoverageOffer $offer): void
    {
        $requester = $request->requester;
        if (! $requester || ! $requester->line_user_id) return;

        $shop = Shop::query()->withoutShopScope()->find($requester->shop_id);
        $volunteer = $offer->volunteer;

        $this->notifier->dispatchCoverageOffered(
            $shop, $requester, $request, $offer,
            "🙌 {$volunteer?->name} 想接你的代班請求,請至 LIFF 選擇是否接受。".($offer->message ? "\n他說:{$offer->message}" : ''),
        );
    }

    private function notifyCoverageAccepted(ShiftCoverageRequest $request, ShiftCoverageOffer $offer): void
    {
        $shop = Shop::query()->withoutShopScope()->find($request->requester?->shop_id);
        if (! $shop) return;

        // 給 volunteer
        $this->notifier->dispatchCoverageAccepted(
            $shop, $offer->volunteer, $request, $offer,
            "✅ 你已成功接下 {$request->requester?->name} 的班次,班表已更新。",
        );

        // 給 requester
        $this->notifier->dispatchCoverageAccepted(
            $shop, $request->requester, $request, $offer,
            "✅ 代班完成,你的班次將由 {$offer->volunteer?->name} 代替。",
        );
    }
}
