<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_default_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->foreignId('shift_template_id')->constrained()->cascadeOnDelete();
            $table->enum('availability', ['available', 'unavailable', 'maybe'])->default('maybe');
            $table->timestamps();

            $table->unique(['employee_id', 'day_of_week', 'shift_template_id'], 'emp_default_avail_uniq');
        });
    }

    public function down(): void { Schema::dropIfExists('employee_default_availabilities'); }
};
