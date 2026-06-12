<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();         // 內部代碼（給連鎖總部）
            $table->string('logo_url')->nullable();
            $table->string('primary_color', 16)->nullable();
            $table->json('settings_json')->nullable();  // 例：跨店共用班次模板、共用員工池
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'name']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
