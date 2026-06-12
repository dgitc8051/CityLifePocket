<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. 擴充 role enum 加入 admin
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','owner','manager','sub_manager','staff') DEFAULT 'staff'");

        // 2. permissions_json：覆寫角色預設權限（admin 可設定特定使用者）
        Schema::table('users', function (Blueprint $table) {
            $table->json('permissions_json')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permissions_json');
        });
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner','manager','sub_manager','staff') DEFAULT 'staff'");
    }
};
