<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_number')->unique();
            $table->string('name');
            $table->enum('type', ['equipment', 'software'])->default('equipment');
            $table->string('category');
            $table->string('location')->nullable();
            $table->string('model')->nullable();
            $table->string('qr_code')->unique();
            $table->foreignId('team_id')->constrained();
            $table->date('installed_at')->nullable();
            $table->date('warranty_expires_at')->nullable();
            $table->datetime('last_maintained_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
