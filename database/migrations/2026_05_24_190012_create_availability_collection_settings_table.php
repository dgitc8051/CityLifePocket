<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('availability_collection_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('push_day_of_week')->default(4)->comment('0=Sun, 4=Thu');
            $table->time('push_time')->default('20:00:00');
            $table->unsignedTinyInteger('deadline_day_of_week')->default(5);
            $table->time('deadline_time')->default('12:00:00');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('availability_collection_settings'); }
};
