<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // 每天上限工時（軟限制，給演算法參考）
            $table->unsignedSmallInteger('daily_max_hours')->nullable()->after('weekly_max_hours');
            // 每週最低工時（軟限制，正職人員適用）
            $table->unsignedSmallInteger('weekly_min_hours')->nullable()->after('daily_max_hours');
            // 月薪（正職填這個；兼職留空用 hourly_wage）
            $table->unsignedInteger('monthly_salary')->nullable()->after('hourly_wage');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['daily_max_hours', 'weekly_min_hours', 'monthly_salary']);
        });
    }
};
