<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shift_swap_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('to_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('from_schedule_entry_id')->constrained('schedule_entries')->cascadeOnDelete();
            $table->foreignId('to_schedule_entry_id')->nullable()->constrained('schedule_entries')->nullOnDelete();
            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled'])->default('pending');
            $table->string('reason')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['from_employee_id', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('shift_swap_requests'); }
};
