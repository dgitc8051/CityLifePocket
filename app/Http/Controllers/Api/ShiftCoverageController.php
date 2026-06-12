<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ScheduleEntry;
use App\Models\ShiftCoverageOffer;
use App\Models\ShiftCoverageRequest;
use App\Services\ShiftCoverageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShiftCoverageController extends Controller
{
    public function __construct(private ShiftCoverageService $coverage) {}

    /** GET /api/coverage — 店長/管理者列出所有 open requests + offers */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $shop = $user?->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        $status = $request->query('status', 'open');

        $items = ShiftCoverageRequest::query()
            ->whereHas('requester', fn ($q) => $q->where('shop_id', $shop->id))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with([
                'requester:id,name',
                'scheduleEntry.shiftTemplate:id,name,start_time,end_time',
                'offers.volunteer:id,name',
            ])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $items->map(fn ($r) => $this->serialize($r))->values()]);
    }

    /** POST /api/coverage — 員工自己發起 */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        $shop = $user?->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        $data = $request->validate([
            'schedule_entry_id' => 'required|integer|exists:schedule_entries,id',
            'reason' => 'nullable|string|max:255',
        ]);

        $emp = Employee::where('shop_id', $shop->id)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('line_user_id', $user->line_user_id);
            })
            ->first();
        if (! $emp && ! $user->isManager()) {
            return response()->json(['error' => '尚未綁定員工'], 422);
        }

        $entry = ScheduleEntry::findOrFail($data['schedule_entry_id']);

        // manager 可代任何員工發起
        $requester = $user->isManager()
            ? Employee::where('shop_id', $shop->id)->where('id', $entry->employee_id)->firstOrFail()
            : $emp;

        try {
            $created = $this->coverage->open($entry, $requester, $data['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->serialize($created->fresh(['requester', 'scheduleEntry.shiftTemplate', 'offers.volunteer']))], 201);
    }

    /** DELETE /api/coverage/{request} */
    public function destroy(ShiftCoverageRequest $request): JsonResponse
    {
        $user = Auth::user();
        $emp = Employee::where('id', $request->requester_employee_id)->first();
        if (! $user->isManager() && (! $emp || $emp->user_id !== $user->id)) {
            return response()->json(['error' => '無權'], 403);
        }

        try {
            $this->coverage->cancel($request);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'cancelled']);
    }

    /** POST /api/coverage/{request}/accept/{offer} */
    public function accept(ShiftCoverageRequest $request, ShiftCoverageOffer $offer): JsonResponse
    {
        $user = Auth::user();
        $emp = Employee::where('id', $request->requester_employee_id)->first();
        // 只允許 requester 本人或 manager 接受
        if (! $user->isManager() && (! $emp || $emp->user_id !== $user->id)) {
            return response()->json(['error' => '無權'], 403);
        }

        try {
            $updated = $this->coverage->accept($request, $offer);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->serialize($updated)]);
    }

    private function serialize(ShiftCoverageRequest $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'reason' => $r->reason,
            'expires_at' => $r->expires_at?->toIso8601String(),
            'fulfilled_at' => $r->fulfilled_at?->toIso8601String(),
            'requester' => [
                'id' => $r->requester?->id,
                'name' => $r->requester?->name,
            ],
            'entry' => [
                'id' => $r->scheduleEntry?->id,
                'date' => $r->scheduleEntry?->date?->toDateString(),
                'shift_name' => $r->scheduleEntry?->shiftTemplate?->name,
                'start_time' => substr($r->scheduleEntry?->shiftTemplate?->start_time ?? '', 0, 5),
                'end_time' => substr($r->scheduleEntry?->shiftTemplate?->end_time ?? '', 0, 5),
            ],
            'offers' => $r->offers?->map(fn ($o) => [
                'id' => $o->id,
                'status' => $o->status,
                'message' => $o->message,
                'volunteer' => [
                    'id' => $o->volunteer?->id,
                    'name' => $o->volunteer?->name,
                ],
                'responded_at' => $o->responded_at?->toIso8601String(),
            ])->values() ?? [],
            'accepted_employee_id' => $r->accepted_employee_id,
        ];
    }
}
