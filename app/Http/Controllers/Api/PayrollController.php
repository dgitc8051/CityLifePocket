<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Services\Salary\AnnualLeaveCalculator;
use App\Services\Salary\PayrollPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PayrollController extends Controller
{
    public function __construct(private PayrollPeriodService $periods) {}

    /** GET /api/payroll?month=2026-06 — 整月每位員工的薪資預覽 */
    public function period(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user || ! $user->isManager()) {
            return response()->json(['error' => '無權限'], 403);
        }

        $shop = $user->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        $data = $request->validate([
            'month' => 'required|regex:/^\d{4}-\d{2}$/',
        ]);

        $period = $this->periods->getOrOpen($shop, $data['month']);

        // 若已 lock,直接回 summary_json(避免重算);否則即時計算
        $payload = $period->isLocked() && $period->summary_json
            ? $period->summary_json
            : $this->periods->computePeriod($period);

        return response()->json($payload);
    }

    /** POST /api/payroll/{period}/lock */
    public function lock(PayrollPeriod $period): JsonResponse
    {
        $user = Auth::user();
        if (! $user || ! $user->isManager()) {
            return response()->json(['error' => '無權限'], 403);
        }

        $locked = $this->periods->lock($period);
        return response()->json(['data' => [
            'id' => $locked->id,
            'status' => $locked->status,
            'locked_at' => $locked->locked_at?->toIso8601String(),
        ]]);
    }

    /** POST /api/payroll/{period}/paid */
    public function markPaid(PayrollPeriod $period): JsonResponse
    {
        $user = Auth::user();
        if (! $user || ! $user->isManager()) {
            return response()->json(['error' => '無權限'], 403);
        }

        try {
            $period = $this->periods->markPaid($period);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => [
            'id' => $period->id,
            'status' => $period->status,
            'paid_at' => $period->paid_at?->toIso8601String(),
        ]]);
    }

    /** GET /api/payroll/{employee}/payslip?month=YYYY-MM */
    public function payslip(Request $request, Employee $employee): JsonResponse
    {
        $user = Auth::user();
        $shop = $user?->resolveCurrentShop();
        if (! $shop) return response()->json(['error' => 'No shop'], 404);

        // 員工自己或 manager 可查
        if (! $user->isManager() && $employee->user_id !== $user->id) {
            return response()->json(['error' => '無權'], 403);
        }
        if ($employee->shop_id !== $shop->id) {
            return response()->json(['error' => '員工不存在'], 404);
        }

        $data = $request->validate([
            'month' => 'required|regex:/^\d{4}-\d{2}$/',
        ]);

        $period = $this->periods->getOrOpen($shop, $data['month']);

        // 從 period 找這位員工的 row
        $bundle = $period->isLocked() && $period->summary_json
            ? $period->summary_json
            : $this->periods->computePeriod($period);

        $row = collect($bundle['rows'] ?? [])
            ->firstWhere('employee_id', $employee->id);

        if (! $row) {
            return response()->json(['error' => '本月無薪資資料'], 404);
        }

        // 特休資訊
        $accrual = AnnualLeaveCalculator::ensureCurrentAccrual($employee);

        return response()->json([
            'period' => $bundle['period'],
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'hire_date' => $employee->hire_date?->toDateString(),
            ],
            'payslip' => $row,
            'annual_leave' => $accrual ? [
                'cycle_start' => $accrual->cycle_start->toDateString(),
                'cycle_end' => $accrual->cycle_end->toDateString(),
                'quota_days' => (int) $accrual->quota_days,
                'used_days' => (float) $accrual->used_days,
                'remaining_days' => $accrual->remainingDays(),
            ] : null,
        ]);
    }
}
