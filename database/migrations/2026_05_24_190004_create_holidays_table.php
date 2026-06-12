<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('type', ['closed', 'special'])->default('closed');
            $table->string('note')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_rule', 64)->nullable()->comment('yearly | monthly_2nd_sunday | ...');
            $table->timestamps();

            $table->index(['shop_id', 'date']);
        });
    }

    public function down(): void { Schema::dropIfExists('holidays'); }
};
