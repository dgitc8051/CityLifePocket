<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shift_coverage_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('reason', 255)->nullable();
            $table->enum('status', ['open', 'cancelled', 'fulfilled', 'expired'])->default('open');
            $table->timestamp('expires_at')->nullable();
            // accepted_offer_id FK 之後 alter 加,避免 circular reference 順序問題
            $table->unsignedBigInteger('accepted_offer_id')->nullable();
            $table->foreignId('accepted_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('requester_employee_id');
        });

        Schema::create('shift_coverage_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coverage_request_id')->constrained('shift_coverage_requests')->cascadeOnDelete();
            $table->foreignId('volunteer_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('message', 255)->nullable();
            $table->enum('status', ['pending', 'accepted', 'rejected', 'withdrawn'])->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['coverage_request_id', 'volunteer_employee_id'], 'cov_offer_uniq');
            $table->index(['volunteer_employee_id', 'status']);
        });

        // 兩表都建好後,補上 requests.accepted_offer_id 的 FK
        Schema::table('shift_coverage_requests', function (Blueprint $table) {
            $table->foreign('accepted_offer_id')
                ->references('id')->on('shift_coverage_offers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shift_coverage_requests', function (Blueprint $table) {
            $table->dropForeign(['accepted_offer_id']);
        });
        Schema::dropIfExists('shift_coverage_offers');
        Schema::dropIfExists('shift_coverage_requests');
    }
};
