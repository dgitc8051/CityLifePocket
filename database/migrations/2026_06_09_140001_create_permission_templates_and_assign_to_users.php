<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 權限模板系統:
 *
 * 店家擁有者 / 店長 / 副店長 / 員工 → 變成「系統內建模板」
 * 店主可以自訂自己組織專屬的模板,套用到員工身上
 *
 * 每個 user.permission_template_id 指向他套用的模板。
 * 同時 permissions_json 還是存在 — 用來放「在模板上的個人覆寫」。
 *
 * 模板的 permissions_json schema:
 *   { "schedule": "rw", "employees": "r", "audit_logs": "none", ... }
 *
 * is_system=true 表示這個模板由系統內建(店家無法刪除),
 * 但可以「另存為新模板」當作起點。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('permission_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            // organization_id=null 代表全系統內建模板(每個 org 都看得到)
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('permissions_json');     // {key: rw|r|none}
            $table->boolean('is_system')->default(false);   // 系統內建,不可刪除
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_system']);
            $table->unique(['organization_id', 'name']);  // 同 org 內名字不重複
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('permission_template_id')
                ->nullable()
                ->after('permissions_json')
                ->constrained('permission_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['permission_template_id']);
            $table->dropColumn('permission_template_id');
        });
        Schema::dropIfExists('permission_templates');
    }
};
