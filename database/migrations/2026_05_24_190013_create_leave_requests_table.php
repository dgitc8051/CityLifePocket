<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            $table->enum('type', ['personal', 'sick', 'annual', 'funeral', 'marriage', 'other'])->default('personal');
            $table->text('reason')->nullable();
            $table->string('attachment_url')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->enum('source', ['employee', 'manager_proxy', 'ai_detected'])->default('employee');
            $table->timestamp('submitted_at');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamp('line_notified_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['start_datetime', 'end_datetime']);
        });
    }

    public function down(): void { Schema::dropIfExists('leave_requests'); }
};
