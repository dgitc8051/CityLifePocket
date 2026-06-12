<?php

namespace App\Services\Salary;

/**
 * 台灣勞健保 / 勞退分攤計算(2026 規範,需每年人工更新)。
 *
 * 注意:
 *  - 這是「教練版」的簡化表格,實際勞保局有更細的級距檔(202+ 級)
 *  - 真實上線應該從外部 JSON 載入,並按年版本化(2025_table.json, 2026_table.json)
 *  - 投保薪資 < 27470 一律 27470 起跳(2026 基本工資門檻)
 *
 * 公式:
 *   勞保員工自付   = 投保薪資 * 12% * 20%   (員工 20% / 雇主 70% / 政府 10%)
 *   健保員工自付   = 投保薪資 * 5.17% * 30%(平均加保眷屬數 0.61)
 *   勞退雇主提繳   = 投保薪資 * 6% (員工可選擇額外自提 0~6%)
 *
 * 級距規則:投保薪資 = 月薪向上取整到下一級距(下方表格)。
 */
class TaiwanLaborInsurance
{
    /** 2026 月投保薪資級距(常用部分,實際表格更細) */
    private const INSURED_GRADES_2026 = [
        27470, 28590, 30300, 31800, 33300, 34800, 36300, 38200, 40100, 42000,
        43900, 45800, 48200, 50600, 53000, 55400, 57800, 60800, 63800, 66800, 69800,
        72800, 76500, 80200, 83900, 87600, 92100, 96600, 101100, 105600, 110100,
        115500, 120900, 126300, 131700, 137100, 142500, 147900, 150000,
    ];

    /** 健保最高投保(2026 預估) */
    private const HEALTH_INSURANCE_CAP = 250000;

    /** 一般用率(2026,實際數字以衛福部公告為準) */
    private const LABOR_INSURANCE_RATE = 0.12;
    private const LABOR_INSURANCE_EMPLOYEE_SHARE = 0.20;
    private const HEALTH_INSURANCE_RATE = 0.0517;
    private const HEALTH_INSURANCE_EMPLOYEE_SHARE = 0.30;
    private const RETIREMENT_EMPLOYER_RATE = 0.06;

    /**
     * 把任意月薪換到「投保薪資」級距上。
     */
    public static function insuredSalary(float $monthlySalary): int
    {
        $grades = self::INSURED_GRADES_2026;
        foreach ($grades as $grade) {
            if ($monthlySalary <= $grade) return $grade;
        }
        return end($grades); // 上限(賦值給 local 才能 by-reference)
    }

    /**
     * 計算員工自付勞保費(整數元,四捨五入)。
     */
    public static function laborInsuranceEmployee(float $monthlySalary): int
    {
        $insured = self::insuredSalary($monthlySalary);
        return (int) round($insured * self::LABOR_INSURANCE_RATE * self::LABOR_INSURANCE_EMPLOYEE_SHARE);
    }

    /**
     * 計算員工自付健保費(本人,不含眷屬)。
     */
    public static function healthInsuranceEmployee(float $monthlySalary): int
    {
        $insured = min(self::insuredSalary($monthlySalary), self::HEALTH_INSURANCE_CAP);
        return (int) round($insured * self::HEALTH_INSURANCE_RATE * self::HEALTH_INSURANCE_EMPLOYEE_SHARE);
    }

    /**
     * 計算雇主應提繳的勞退金(6%,員工不負擔)。
     */
    public static function retirementEmployer(float $monthlySalary): int
    {
        $insured = self::insuredSalary($monthlySalary);
        return (int) round($insured * self::RETIREMENT_EMPLOYER_RATE);
    }

    /**
     * 一次拿全部:[insured, labor_emp, health_emp, retirement_employer]
     */
    public static function breakdown(float $monthlySalary): array
    {
        $insured = self::insuredSalary($monthlySalary);
        return [
            'insured_salary' => $insured,
            'labor_insurance_employee' => self::laborInsuranceEmployee($monthlySalary),
            'health_insurance_employee' => self::healthInsuranceEmployee($monthlySalary),
            'retirement_employer' => self::retirementEmployer($monthlySalary),
            'table_year' => 2026,
        ];
    }
}
