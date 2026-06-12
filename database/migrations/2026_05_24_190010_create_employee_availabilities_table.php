<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('week_start_date');
            $table->unsignedTinyInteger('day_of_week');
            $table->foreignId('shift_template_id')->constrained()->cascadeOnDelete();
            $table->enum('availability', ['available', 'unavailable', 'maybe'])->default('maybe');
            $table->string('note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->enum('source', ['employee', 'manager_proxy'])->default('employee');
            $table->timestamps();

            $table->unique(['employee_id', 'week_start_date', 'day_of_week', 'shift_template_id'], 'emp_avail_uniq');
            $table->index(['employee_id', 'week_start_date']);
        });
    }

    public function down(): void { Schema::dropIfExists('employee_availabilities'); }
};
