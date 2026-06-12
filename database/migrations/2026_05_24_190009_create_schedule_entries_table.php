<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('schedule_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_template_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->time('custom_start_time')->nullable();
            $table->time('custom_end_time')->nullable();
            $table->enum('status', ['scheduled', 'confirmed', 'swapped', 'cancelled'])->default('scheduled');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['schedule_id', 'employee_id', 'shift_template_id', 'date'], 'sched_entry_uniq');
            $table->index(['employee_id', 'date']);
        });
    }

    public function down(): void { Schema::dropIfExists('schedule_entries'); }
};
