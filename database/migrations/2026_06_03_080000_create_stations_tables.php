<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. 店家自訂站別主檔
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            $table->string('color', 16)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['shop_id', 'is_active', 'sort_order']);
            $table->unique(['shop_id', 'name']);
        });

        // 2. 員工會哪幾個站
        Schema::create('employee_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['employee_id', 'station_id']);
            $table->index('station_id');
        });

        // 3. 時段樣板需要哪幾個站，各至少幾人
        Schema::create('shift_template_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('min_count')->default(1);
            $table->timestamps();

            $table->unique(['shift_template_id', 'station_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_template_stations');
        Schema::dropIfExists('employee_stations');
        Schema::dropIfExists('stations');
    }
};
