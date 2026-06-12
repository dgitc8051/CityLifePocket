<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            // 訂閱 / 計費（Phase 0 先佔位，Stripe / 綠界接入時延伸）
            $table->string('plan')->default('free');                 // free | starter | pro | enterprise
            $table->string('status')->default('active');             // active | trialing | past_due | suspended | canceled
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_renews_at')->nullable();
            $table->unsignedSmallInteger('shop_quota')->default(1);  // 方案內含店數
            $table->unsignedSmallInteger('seat_quota')->default(10); // 方案內含員工數
            $table->json('settings_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'plan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
