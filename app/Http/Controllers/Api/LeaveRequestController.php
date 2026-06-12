<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Shop;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $query = LeaveRequest::query()
            ->with('employee:id,name,level,shop_id')
            ->whereHas('employee', fn ($q) => $q->where('shop_id', $shop->id))
            ->orderByDesc('submitted_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($empId = $request->query('employee_id')) {
            $query->where('employee_id', $empId);
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('start_datetime', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('start_datetime', '<=', $to);
        }

        $leaves = $query->get()->map(fn ($l) => $this->transform($l));

        return response()->json([
            'data' => $leaves,
            'meta' => [
                'total' => $leaves->count(),
                'pending' => $leaves->where('status', 'pending')->count(),
                'approved' => $leaves->where('status', 'approved')->count(),
                'rejected' => $leaves->where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'start_datetime' => 'required|date',
            'end_datetime' => 'required|date|after_or_equal:start_datetime',
            'type' => 'required|in:personal,sick,annual,funeral,marriage,other',
            'reason' => 'nullable|string|max:500',
            'source' => 'nullable|in:employee,manager_proxy,ai_detected',
        ]);

        // 檢查提前申請天數（店長代填或病假/喪假可豁免）
        $user = Auth::user();
        $isManager = $user?->isManager() ?? false;
        $source = $data['source'] ?? 'manager_proxy';

        if (! $isManager && $source === 'employee' && ! in_array($data['type'], ['sick', 'funeral'], true)) {
            $shop = Auth::user()?->resolveCurrentShop();
            $rules = $shop?->settings_json ?? [];
            $minDays = (int) ($rules['leave_min_advance_days'] ?? 0);

            if ($minDays > 0) {
                $start = CarbonImmutable::parse($data['start_datetime']);
                $now = CarbonImmutable::now();
                $diffDays = $now->startOfDay()->diffInDays($start->startOfDay(), false);

                if ($diffDays < $minDays) {
                    return response()->json([
                        'error' => "事假需提前 {$minDays} 天申請，請聯絡店長代填或改為病假",
                        'required_advance_days' => $minDays,
                        'current_advance_days' => max(0, $diffDays),
                    ], 422);
                }
            }
        }

        $leave = LeaveRequest::create([
            ...$data,
            'status' => 'pending',
            'source' => $source,
            'submitted_at' => now(),
        ]);

        AuditService::log('create', $leave, null, $leave->toArray());

        return response()->json(['data' => $this->transform($leave->fresh('employee'))], 201);
    }

    public function show(LeaveRequest $leaveRequest): JsonResponse
    {
        return response()->json(['data' => $this->transform($leaveRequest->load('employee'))]);
    }

    public function update(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        if ($leaveRequest->status !== 'pending') {
            return response()->json(['error' => '只能編輯待審核中的請假'], 409);
        }

        $data = $request->validate([
            'start_datetime' => 'sometimes|required|date',
            'end_datetime' => 'sometimes|required|date',
            'type' => 'sometimes|required|in:personal,sick,annual,funeral,marriage,other',
            'reason' => 'nullable|string|max:500',
        ]);

        $before = $leaveRequest->toArray();
        $leaveRequest->update($data);
        AuditService::log('update', $leaveRequest, $before, $leaveRequest->toArray());

        return response()->json(['data' => $this->transform($leaveRequest->fresh('employee'))]);
    }

    public function destroy(LeaveRequest $leaveRequest): JsonResponse
    {
        if (! in_array($leaveRequest->status, ['pending'], true)) {
            return response()->json(['error' => '只能取消待審核中的請假'], 409);
        }

        $before = $leaveRequest->toArray();
        $leaveRequest->update(['status' => 'cancelled']);
        AuditService::log('cancel', $leaveRequest, $before, $leaveRequest->toArray());

        return response()->json(['message' => 'Cancelled']);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        if ($leaveRequest->status !== 'pending') {
            return response()->json(['error' => '此請假已處理過'], 409);
        }

        $data = $request->validate([
            'review_note' => 'nullable|string|max:500',
        ]);

        $before = $leaveRequest->toArray();
        $leaveRequest->update([
            'status' => 'approved',
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
        ]);
        AuditService::log('approve', $leaveRequest, $before, $leaveRequest->toArray());

        $this->notifyEmployee($leaveRequest);

        return response()->json(['data' => $this->transform($leaveRequest->fresh('employee'))]);
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        if ($leaveRequest->status !== 'pending') {
            return response()->json(['error' => '此請假已處理過'], 409);
        }

        $data = $request->validate([
            'review_note' => 'required|string|max:500',
        ]);

        $before = $leaveRequest->toArray();
        $leaveRequest->update([
            'status' => 'rejected',
            'reviewed_by_user_id' => Auth::id(),
            'reviewed_at' => now(),
            'review_note' => $data['review_note'],
        ]);
        AuditService::log('reject', $leaveRequest, $before, $leaveRequest->toArray());

        $this->notifyEmployee($leaveRequest);

        return response()->json(['data' => $this->transform($leaveRequest->fresh('employee'))]);
    }

    private function notifyEmployee(LeaveRequest $leave): void
    {
        try {
            $sent = app(\App\Services\Line\NotificationDispatcher::class)
                ->dispatchLeaveReviewed($leave);
            if ($sent) {
                $leave->line_notified_at = now();
                $leave->save();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('LeaveRequest notify failed', ['error' => $e->getMessage()]);
        }
    }

    private function transform(LeaveRequest $l): array
    {
        return [
            'id' => $l->id,
            'employee_id' => $l->employee_id,
            'employee_name' => $l->employee?->name,
            'start_datetime' => $l->start_datetime?->toIso8601String(),
            'end_datetime' => $l->end_datetime?->toIso8601String(),
            'type' => $l->type,
            'type_label' => match ($l->type) {
                'personal' => '事假',
                'sick' => '病假',
                'annual' => '特休',
                'funeral' => '喪假',
                'marriage' => '婚假',
                default => '其他',
            },
            'reason' => $l->reason,
            'status' => $l->status,
            'status_label' => match ($l->status) {
                'pending' => '待審核',
                'approved' => '已核准',
                'rejected' => '已拒絕',
                'cancelled' => '已取消',
                default => $l->status,
            },
            'source' => $l->source,
            'submitted_at' => $l->submitted_at?->toIso8601String(),
            'reviewed_at' => $l->reviewed_at?->toIso8601String(),
            'review_note' => $l->review_note,
        ];
    }
}
