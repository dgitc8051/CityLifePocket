<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 薪資引擎所需的兩張新表:
 *
 * payroll_periods - 月薪期間結算狀態
 *   每店每月一筆,記錄這個月的薪資結算是 draft/locked/paid
 *   一次性把所有員工的時數凍結,後續即使有人補打卡也不影響已結算的薪資
 *
 * annual_leave_accruals - 員工每年的特休配額與使用狀況
 *   依勞基法 §38:6 個月 3 天、1 年 7 天、2 年 10 天、3 年 14 天、5 年 15 天、10+ 年每年+1 上限 30
 *   每員工每「年資週年」一筆 row,記錄該年度配額、已用、剩餘
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');      // 通常 = 該月 1 號
            $table->date('period_end');        // 該月最後一天
            $table->string('label', 32);        // 例如 "2026-06"
            $table->enum('status', ['draft', 'locked', 'paid'])->default('draft');
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('summary_json')->nullable();   // 全員加總(總工時、總薪資、總勞健保)
            $table->timestamps();

            $table->unique(['shop_id', 'label']);
            $table->index(['shop_id', 'status']);
        });

        Schema::create('annual_leave_accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('cycle_start');                       // 該年資週期起始(到職日的整年週年)
            $table->date('cycle_end');                          // 週期結束
            $table->unsignedSmallInteger('quota_days');         // 該週期配額(法定天數)
            $table->decimal('used_days', 5, 2)->default(0);     // 已用(可拆 0.5 半天)
            $table->decimal('expired_days', 5, 2)->default(0);  // 過期未休
            $table->decimal('payout_days', 5, 2)->default(0);   // 折算工資的天數
            $table->json('basis_json')->nullable();             // 計算依據(年資、條款、覆蓋規則)
            $table->timestamps();

            $table->unique(['employee_id', 'cycle_start']);
            $table->index(['employee_id', 'cycle_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annual_leave_accruals');
        Schema::dropIfExists('payroll_periods');
    }
};
