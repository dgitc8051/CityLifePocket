<?php

namespace App\Services\Salary;

use App\Models\AnnualLeaveAccrual;
use App\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * 依勞基法 §38 計算特休配額(年資累進)。
 *
 * 法定表:
 *   6 個月 ~ 1 年 :  3 天
 *   1 ~ 2 年      :  7 天
 *   2 ~ 3 年      : 10 天
 *   3 ~ 5 年      : 14 天
 *   5 ~ 10 年     : 15 天
 *   10+ 年        : 每滿 1 年 +1,上限 30 天
 *
 * 週期定義:以到職日為起點,「到職日 + N 年」起算下一年的特休。
 * 第一個週期較特殊:6 個月後拿 3 天,跑滿一年再切換到 7 天。
 *
 * 本實作做的是「目前年度的配額」與「下個週期換算」。
 */
class AnnualLeaveCalculator
{
    /** 回傳目前(以 today 為準)該員工所在週期的配額天數。 */
    public static function currentQuotaDays(Employee $emp, ?CarbonImmutable $asOf = null): int
    {
        $asOf = $asOf ?? CarbonImmutable::today();
        if (! $emp->hire_date) return 0;
        $hire = CarbonImmutable::parse($emp->hire_date);

        $yearsServed = $hire->diffInYears($asOf);
        $monthsServed = $hire->diffInMonths($asOf);

        if ($monthsServed < 6) return 0;

        // 第一年內(滿 6 月 ~ 不滿 1 年)
        if ($yearsServed < 1) return 3;

        return self::quotaByCompletedYears((int) floor($yearsServed));
    }

    /**
     * 取得 / 建立員工當前年度的 AnnualLeaveAccrual row。
     */
    public static function ensureCurrentAccrual(Employee $emp, ?CarbonImmutable $asOf = null): ?AnnualLeaveAccrual
    {
        $asOf = $asOf ?? CarbonImmutable::today();
        if (! $emp->hire_date) return null;

        $hire = CarbonImmutable::parse($emp->hire_date);
        [$start, $end, $quota] = self::cycleBoundaries($hire, $asOf);

        return AnnualLeaveAccrual::withoutShopScope()->firstOrCreate(
            [
                'employee_id' => $emp->id,
                'cycle_start' => $start->toDateString(),
            ],
            [
                'cycle_end' => $end->toDateString(),
                'quota_days' => $quota,
                'basis_json' => [
                    'hire_date' => $hire->toDateString(),
                    'rule' => '勞基法 §38',
                    'computed_at' => $asOf->toIso8601String(),
                ],
            ]
        );
    }

    /**
     * 找出目前所在週期的 [cycle_start, cycle_end, quota]。
     */
    public static function cycleBoundaries(CarbonImmutable $hire, CarbonImmutable $asOf): array
    {
        $monthsServed = $hire->diffInMonths($asOf);

        // 半年週期(到職 ~ 滿 6 月)— 還沒入特休,但 row 還是需要(配額 0)
        if ($monthsServed < 6) {
            return [$hire, $hire->addMonths(6)->subDay(), 0];
        }

        // 6 月 ~ 1 年:配額 3 天的週期
        if ($monthsServed < 12) {
            return [$hire->addMonths(6), $hire->addYear()->subDay(), 3];
        }

        // 之後:以到職日為週年的整年週期
        $yearsCompleted = (int) floor($hire->diffInYears($asOf));
        $start = $hire->addYears($yearsCompleted);
        $end = $start->addYear()->subDay();
        $quota = self::quotaByCompletedYears($yearsCompleted);
        return [$start, $end, $quota];
    }

    private static function quotaByCompletedYears(int $yearsCompleted): int
    {
        return match (true) {
            $yearsCompleted < 1 => 3,        // 6 月 ~ 1 年(理論上不會在這 branch)
            $yearsCompleted < 2 => 7,
            $yearsCompleted < 3 => 10,
            $yearsCompleted < 5 => 14,
            $yearsCompleted < 10 => 15,
            default => min(30, 15 + ($yearsCompleted - 9)),
        };
    }
}
