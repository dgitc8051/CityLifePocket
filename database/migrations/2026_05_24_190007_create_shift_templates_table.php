<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shift_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('days_of_week_bitmask')->default(127)
                ->comment('bit per day: 1=Sun, 2=Mon, 4=Tue, 8=Wed, 16=Thu, 32=Fri, 64=Sat');
            $table->unsignedSmallInteger('required_score')->default(0);
            $table->unsignedTinyInteger('min_senior_count')->default(0);
            $table->unsignedTinyInteger('min_headcount')->default(1);
            $table->unsignedTinyInteger('max_headcount')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'is_active']);
        });
    }

    public function down(): void { Schema::dropIfExists('shift_templates'); }
};
