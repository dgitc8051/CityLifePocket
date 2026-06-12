<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('line_channel_id')->nullable();
            $table->text('line_channel_secret_encrypted')->nullable();
            $table->string('timezone', 64)->default('Asia/Taipei');
            $table->json('settings_json')->nullable();
            $table->timestamps();

            $table->index('owner_user_id');
        });
    }

    public function down(): void { Schema::dropIfExists('shops'); }
};
