<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('clocked_in_at');
            $table->timestamp('clocked_out_at')->nullable();
            // late / on_time / early / no_show / present_unscheduled（沒排但有來）
            $table->enum('status', ['on_time', 'late', 'early', 'no_show', 'present_unscheduled'])->default('on_time');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'clocked_in_at']);
            $table->index(['schedule_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
