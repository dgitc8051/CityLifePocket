<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 強化 line_notifications:
 *   - shop_id:多店環境用對的 LINE channel 推送
 *   - direction:in (從用戶來) / out (推給用戶)
 *   - template_key:範本識別,前端統計分類用
 *   - idempotency_key:同一事件不要推兩次(例如重複發布班表)
 *   - scheduled_at:延後推送(預提醒、班前提醒)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('line_notifications', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->after('id')->constrained('shops')->nullOnDelete();
            $table->enum('direction', ['in', 'out'])->default('out')->after('type');
            $table->string('template_key', 64)->nullable()->after('direction');
            $table->string('idempotency_key', 128)->nullable()->after('template_key');
            $table->timestamp('scheduled_at')->nullable()->after('sent_at');

            $table->unique('idempotency_key');
            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'scheduled_at']);
        });

        // backfill shop_id 給既有 row(若有的話):透過 employee_id → shop_id
        DB::statement("
            UPDATE line_notifications ln
            JOIN employees e ON e.id = ln.employee_id
            SET ln.shop_id = e.shop_id
            WHERE ln.shop_id IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('line_notifications', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['shop_id', 'status']);
            $table->dropIndex(['shop_id', 'scheduled_at']);
            $table->dropForeign(['shop_id']);
            $table->dropColumn(['shop_id', 'direction', 'template_key', 'idempotency_key', 'scheduled_at']);
        });
    }
};
