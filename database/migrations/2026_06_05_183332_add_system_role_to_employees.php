<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // 系統登入權限（對應 User::ROLE_PERMISSIONS）
            // 不要跟 employees.level（工作能力職階）混淆
            $table->string('system_role', 32)->default('staff')->after('binding_level');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('system_role');
        });
    }
};
