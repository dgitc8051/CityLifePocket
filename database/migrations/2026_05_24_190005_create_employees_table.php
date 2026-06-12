<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('null for L0 employees without LINE binding');
            $table->enum('binding_level', ['L0', 'L1', 'L2'])->default('L0')
                ->comment('L0=data only, L1=line notify, L2=self-service');
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('line_user_id', 64)->nullable();
            $table->unsignedTinyInteger('skill_score')->default(1)->comment('1-10');
            $table->enum('level', ['trainee', 'junior', 'senior', 'lead'])->default('trainee');
            $table->enum('employment_type', ['full', 'part', 'intern'])->default('part');
            $table->date('hire_date')->nullable();
            $table->date('leave_date')->nullable();
            $table->enum('status', ['active', 'leave', 'terminated'])->default('active');
            $table->unsignedSmallInteger('weekly_max_hours')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index('line_user_id');
        });
    }

    public function down(): void { Schema::dropIfExists('employees'); }
};
