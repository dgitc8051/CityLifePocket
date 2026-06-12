<?php

namespace App\Services\Salary;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Shop;
use App\Services\SalaryCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 月薪結算(每店每月):
 *   1. 開期間 (open)
 *   2. 整月每員工的時數 / 加班 bucket 加總
 *   3. 計算薪資毛額 → 扣勞健保 → 員工實領
 *   4. 鎖定 (lock) → 之後補打卡不影響已結算的金額
 *   5. 標記已付 (mark paid)
 *
 * 注意:
 *   - 時薪員工(hourly_wage > 0)按時數計算
 *   - 月薪員工(monthly_salary > 0)整月固定,只有加班費另計
 *   - 兩個都有的 → 月薪優先,時薪當補充(罕見)
 */
class PayrollPeriodService
{
    /**
     * 拿到或建立某月份的 period。
     */
    public function getOrOpen(Shop $shop, string $monthLabel): PayrollPeriod
    {
        // monthLabel = "2026-06"
        $start = CarbonImmutable::parse($monthLabel.'-01')->startOfMonth();
        $end = $start->endOfMonth();

        return PayrollPeriod::firstOrCreate(
            ['shop_id' => $shop->id, 'label' => $monthLabel],
            [
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'status' => 'draft',
            ]
        );
    }

    /**
     * 計算整個 period 的所有員工薪資明細(不寫入 DB,只回 JSON)。
     */
    public function computePeriod(PayrollPeriod $period): array
    {
        $shop = $period->shop;
        $start = CarbonImmutable::parse($period->period_start)->startOfDay();
        $end = CarbonImmutable::parse($period->period_end)->endOfDay();

        $employees = $shop->employees()
            ->where(function ($q) use ($end) {
                $q->whereNull('leave_date')->orWhere('leave_date', '>', $end->toDateString());
            })
            ->get();

        $calc = new SalaryCalculator($shop);
        $rows = [];
        $totals = [
            'employee_count' => 0,
            'gross_pay_sum' => 0,
            'net_pay_sum' => 0,
            'labor_insurance_sum' => 0,
            'health_insurance_sum' => 0,
            'work_minutes_sum' => 0,
            'ot_approved_minutes_sum' => 0,
        ];

        foreach ($employees as $emp) {
            $row = $this->computeEmployee($emp, $shop, $calc, $start, $end);
            $rows[] = $row;
            $totals['employee_count']++;
            $totals['gross_pay_sum'] += $row['gross_pay'];
            $totals['net_pay_sum'] += $row['net_pay'];
            $totals['labor_insurance_sum'] += $row['labor_insurance_employee'];
            $totals['health_insurance_sum'] += $row['health_insurance_employee'];
            $totals['work_minutes_sum'] += $row['work_minutes'];
            $totals['ot_approved_minutes_sum'] += $row['ot_approved_minutes'];
        }

        return [
            'period' => [
                'id' => $period->id,
                'label' => $period->label,
                'status' => $period->status,
                'period_start' => $period->period_start->toDateString(),
                'period_end' => $period->period_end->toDateString(),
                'locked_at' => $period->locked_at?->toIso8601String(),
                'paid_at' => $period->paid_at?->toIso8601String(),
            ],
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * 單一員工的薪資結算 row。
     */
    public function computeEmployee(Employee $emp, Shop $shop, SalaryCalculator $calc, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $hours = $calc->calculateForEmployee($emp, $start, $end);
        $workMinutes = $hours['work_minutes'];
        $otApprovedMinutes = $hours['ot_approved_minutes'];

        $hourlyWage = (int) ($emp->hourly_wage ?? 0);
        $monthlySalary = (int) ($emp->monthly_salary ?? 0);

        $basePay = 0;
        $otPay = 0;

        if ($monthlySalary > 0) {
            // 月薪固定 + 加班費另計(以「平均時薪 = monthly_salary / 30 / 8」為基數)
            $basePay = $monthlySalary;
            $hourlyEq = $monthlySalary / 240; // 30 天 * 8 小時
            $otPay = $this->bucketsToPay($hours['buckets'], $hourlyEq);
        } else {
            // 時薪:普通工時 + bucket 加班
            $basePay = (int) round($hourlyWage * ($workMinutes - $otApprovedMinutes) / 60);
            $otPay = $this->bucketsToPay($hours['buckets'], $hourlyWage);
        }

        $grossPay = $basePay + $otPay;

        // 沒底薪 / 沒月薪 / 整月沒出勤 → 整筆 skip(避免員工資料未填產生負實領)
        if ($grossPay <= 0 && $monthlySalary <= 0) {
            return [
                'employee_id' => $emp->id,
                'employee_name' => $emp->name,
                'employment_type' => $emp->employment_type ?? 'unknown',
                'hourly_wage' => $hourlyWage,
                'monthly_salary' => $monthlySalary,
                'work_minutes' => $workMinutes,
                'ot_approved_minutes' => $otApprovedMinutes,
                'base_pay' => 0, 'ot_pay' => 0, 'gross_pay' => 0,
                'labor_insurance_employee' => 0, 'health_insurance_employee' => 0,
                'insured_salary' => 0, 'net_pay' => 0,
                'buckets' => $hours['buckets'],
                'skipped_reason' => '未設薪資且本月無出勤',
            ];
        }

        $insurance = TaiwanLaborInsurance::breakdown(max($monthlySalary, $grossPay));

        $netPay = $grossPay
            - $insurance['labor_insurance_employee']
            - $insurance['health_insurance_employee'];

        return [
            'employee_id' => $emp->id,
            'employee_name' => $emp->name,
            'employment_type' => $emp->employment_type ?? ($monthlySalary > 0 ? 'salaried' : 'hourly'),
            'hourly_wage' => $hourlyWage,
            'monthly_salary' => $monthlySalary,
            'work_minutes' => $workMinutes,
            'ot_approved_minutes' => $otApprovedMinutes,
            'base_pay' => $basePay,
            'ot_pay' => $otPay,
            'gross_pay' => $grossPay,
            'labor_insurance_employee' => $insurance['labor_insurance_employee'],
            'health_insurance_employee' => $insurance['health_insurance_employee'],
            'insured_salary' => $insurance['insured_salary'],
            'net_pay' => $netPay,
            'buckets' => $hours['buckets'],
        ];
    }

    /**
     * 鎖定 period:寫入 summary_json,後續 attendance 補打卡不影響薪資。
     */
    public function lock(PayrollPeriod $period): PayrollPeriod
    {
        if ($period->isLocked()) return $period;

        $computed = $this->computePeriod($period);

        DB::transaction(function () use ($period, $computed) {
            $period->update([
                'status' => 'locked',
                'locked_at' => now(),
                'summary_json' => $computed,
            ]);
        });

        return $period->fresh();
    }

    public function markPaid(PayrollPeriod $period): PayrollPeriod
    {
        if (! $period->isLocked()) {
            throw new \RuntimeException('必須先 lock 才能標記為已付');
        }
        $period->update(['status' => 'paid', 'paid_at' => now()]);
        return $period->fresh();
    }

    private function bucketsToPay(array $buckets, float $hourlyBase): int
    {
        $total = 0;
        foreach ($buckets as $b) {
            $minutes = (int) ($b['minutes'] ?? 0);
            $multiplier = (float) ($b['multiplier'] ?? 1.0);
            $total += $hourlyBase * ($minutes / 60) * $multiplier;
        }
        return (int) round($total);
    }
}
