<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('role_name', 64)->comment('e.g., 收銀, 手沖, 備料');
            $table->timestamps();

            $table->unique(['employee_id', 'role_name']);
        });
    }

    public function down(): void { Schema::dropIfExists('employee_roles'); }
};
