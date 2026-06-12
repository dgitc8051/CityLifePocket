<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ScheduleEntry;
use App\Models\ShiftSwapRequest;
use App\Models\Shop;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ShiftSwapRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $statusFilter = $request->query('status');

        $query = ShiftSwapRequest::query()
            ->whereHas('fromEmployee', fn ($q) => $q->where('shop_id', $shop->id))
            ->with(['fromEmployee:id,name,level', 'toEmployee:id,name,level', 'fromEntry.shiftTemplate:id,name,start_time,end_time', 'toEntry.shiftTemplate:id,name,start_time,end_time'])
            ->orderByDesc('requested_at');

        if ($statusFilter && in_array($statusFilter, ['pending', 'accepted', 'rejected', 'cancelled'], true)) {
            $query->where('status', $statusFilter);
        }

        $rows = $query->limit(200)->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->serialize($r)),
            'meta' => [
                'pending_count' => ShiftSwapRequest::query()
                    ->whereHas('fromEmployee', fn ($q) => $q->where('shop_id', $shop->id))
                    ->where('status', 'pending')->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_schedule_entry_id' => 'required|exists:schedule_entries,id',
            'to_employee_id' => 'required|exists:employees,id',
            'to_schedule_entry_id' => 'nullable|exists:schedule_entries,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $fromEntry = ScheduleEntry::with('employee')->find($data['from_schedule_entry_id']);
        if (! $fromEntry || $fromEntry->employee->shop_id !== $shop->id) {
            return response()->json(['error' => '排班項目不在本店'], 422);
        }
        if ($data['to_employee_id'] == $fromEntry->employee_id) {
            return response()->json(['error' => '不能跟自己換班'], 422);
        }

        $toEmployee = Employee::find($data['to_employee_id']);
        if (! $toEmployee || $toEmployee->shop_id !== $shop->id) {
            return response()->json(['error' => '對方員工不在本店'], 422);
        }

        $req = ShiftSwapRequest::create([
            'from_employee_id' => $fromEntry->employee_id,
            'to_employee_id' => $data['to_employee_id'],
            'from_schedule_entry_id' => $data['from_schedule_entry_id'],
            'to_schedule_entry_id' => $data['to_schedule_entry_id'] ?? null,
            'status' => 'pending',
            'reason' => $data['reason'] ?? null,
            'requested_at' => now(),
        ]);

        AuditService::log('shift_swap.create', $req, null, $req->toArray(), $shop->id);

        return response()->json(['data' => $this->serialize($req->fresh(['fromEmployee', 'toEmployee', 'fromEntry.shiftTemplate', 'toEntry.shiftTemplate']))], 201);
    }

    public function approve(ShiftSwapRequest $shiftSwapRequest): JsonResponse
    {
        $user = Auth::user();
        if (! $user || ! $user->isManager()) {
            return response()->json(['error' => '只有店長以上可以核准換班'], 403);
        }
        if ($shiftSwapRequest->status !== 'pending') {
            return response()->json(['error' => '此申請已處理過'], 422);
        }

        // 對象員工必須在職
        $toEmployee = Employee::find($shiftSwapRequest->to_employee_id);
        if (! $toEmployee || $toEmployee->status !== 'active') {
            return response()->json(['error' => '對方員工已離職或請長假，無法核准換班'], 422);
        }

        $shop = Auth::user()?->resolveCurrentShop();

        DB::transaction(function () use ($shiftSwapRequest) {
            $from = ScheduleEntry::find($shiftSwapRequest->from_schedule_entry_id);
            $to = $shiftSwapRequest->to_schedule_entry_id
                ? ScheduleEntry::find($shiftSwapRequest->to_schedule_entry_id)
                : null;

            if ($from && $to) {
                // 雙方換班：把 from 的員工換成 to 的員工，反之亦然
                [$from->employee_id, $to->employee_id] = [$to->employee_id, $from->employee_id];
                $from->status = 'swapped';
                $to->status = 'swapped';
                $from->save();
                $to->save();
            } elseif ($from) {
                // 單向代班：from 轉給 to 員工
                $from->employee_id = $shiftSwapRequest->to_employee_id;
                $from->status = 'swapped';
                $from->save();
            }

            $shiftSwapRequest->status = 'accepted';
            $shiftSwapRequest->reviewed_at = now();
            $shiftSwapRequest->save();
        });

        AuditService::log('shift_swap.approve', $shiftSwapRequest, null, $shiftSwapRequest->toArray(), $shop?->id);

        return response()->json(['data' => $this->serialize($shiftSwapRequest->fresh(['fromEmployee', 'toEmployee', 'fromEntry.shiftTemplate', 'toEntry.shiftTemplate']))]);
    }

    public function reject(Request $request, ShiftSwapRequest $shiftSwapRequest): JsonResponse
    {
        $user = Auth::user();
        if (! $user || ! $user->isManager()) {
            return response()->json(['error' => '只有店長以上可以拒絕換班'], 403);
        }
        if ($shiftSwapRequest->status !== 'pending') {
            return response()->json(['error' => '此申請已處理過'], 422);
        }

        $shiftSwapRequest->status = 'rejected';
        $shiftSwapRequest->reviewed_at = now();
        $shiftSwapRequest->save();

        $shop = Auth::user()?->resolveCurrentShop();
        AuditService::log('shift_swap.reject', $shiftSwapRequest, null, $shiftSwapRequest->toArray(), $shop?->id);

        return response()->json(['data' => $this->serialize($shiftSwapRequest->fresh(['fromEmployee', 'toEmployee', 'fromEntry.shiftTemplate', 'toEntry.shiftTemplate']))]);
    }

    public function destroy(ShiftSwapRequest $shiftSwapRequest): JsonResponse
    {
        $user = Auth::user();
        // 員工只能取消自己送的；店長可以取消任何
        if (! $user || (! $user->isManager() && ! $this->isOwnRequest($user, $shiftSwapRequest))) {
            return response()->json(['error' => '無權取消此申請'], 403);
        }
        if (! in_array($shiftSwapRequest->status, ['pending'], true)) {
            return response()->json(['error' => '只有 pending 狀態可以取消'], 422);
        }
        $shiftSwapRequest->status = 'cancelled';
        $shiftSwapRequest->reviewed_at = now();
        $shiftSwapRequest->save();

        $shop = Auth::user()?->resolveCurrentShop();
        AuditService::log('shift_swap.cancel', $shiftSwapRequest, null, $shiftSwapRequest->toArray(), $shop?->id);

        return response()->json(['data' => $this->serialize($shiftSwapRequest->fresh(['fromEmployee', 'toEmployee', 'fromEntry.shiftTemplate', 'toEntry.shiftTemplate']))]);
    }

    private function isOwnRequest($user, ShiftSwapRequest $r): bool
    {
        $employee = Employee::where('user_id', $user->id)->first();
        return $employee && $employee->id === $r->from_employee_id;
    }

    private function serialize(ShiftSwapRequest $r): array
    {
        $statusLabel = match ($r->status) {
            'pending' => '待審核',
            'accepted' => '已通過',
            'rejected' => '已拒絕',
            'cancelled' => '已取消',
            default => $r->status,
        };

        return [
            'id' => $r->id,
            'status' => $r->status,
            'status_label' => $statusLabel,
            'reason' => $r->reason,
            'requested_at' => $r->requested_at?->toIso8601String(),
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'from_employee' => $r->fromEmployee ? ['id' => $r->fromEmployee->id, 'name' => $r->fromEmployee->name] : null,
            'to_employee' => $r->toEmployee ? ['id' => $r->toEmployee->id, 'name' => $r->toEmployee->name] : null,
            'from_entry' => $r->fromEntry ? [
                'id' => $r->fromEntry->id,
                'date' => Carbon::parse($r->fromEntry->date)->toDateString(),
                'shift_name' => $r->fromEntry->shiftTemplate?->name,
                'start_time' => substr($r->fromEntry->shiftTemplate?->start_time ?? '', 0, 5),
                'end_time' => substr($r->fromEntry->shiftTemplate?->end_time ?? '', 0, 5),
            ] : null,
            'to_entry' => $r->toEntry ? [
                'id' => $r->toEntry->id,
                'date' => Carbon::parse($r->toEntry->date)->toDateString(),
                'shift_name' => $r->toEntry->shiftTemplate?->name,
                'start_time' => substr($r->toEntry->shiftTemplate?->start_time ?? '', 0, 5),
                'end_time' => substr($r->toEntry->shiftTemplate?->end_time ?? '', 0, 5),
            ] : null,
        ];
    }
}
