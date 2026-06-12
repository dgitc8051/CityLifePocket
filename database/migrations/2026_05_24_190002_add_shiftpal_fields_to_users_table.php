<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('line_user_id', 64)->nullable()->unique()->after('email');
            $table->string('phone', 32)->nullable()->after('line_user_id');
            $table->enum('role', ['owner', 'manager', 'sub_manager', 'staff'])->default('staff')->after('phone');
            $table->string('avatar_url')->nullable()->after('role');
            $table->foreignId('current_shop_id')->nullable()->after('avatar_url')->constrained('shops')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_shop_id');
            $table->dropColumn(['line_user_id', 'phone', 'role', 'avatar_url']);
        });
    }
};
