<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * employees 加 permission_template_id
 *
 * 用途:員工資料就是登入帳號的「設定來源」— 編輯員工時直接挑模板,
 * 儲存時自動同步到 users.permission_template_id(經過 syncPermissionsToUser)。
 *
 * 員工有可能還沒綁定 user_id(LINE 還沒登入),那 template_id 就先存在 employee 上,
 * 之後 user 綁定起來時自動把模板套上去。
 *
 * 同時 backfill:每個既有 employee 依 system_role → 對應的系統模板 id。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('permission_template_id')
                ->nullable()
                ->after('system_role')
                ->constrained('permission_templates')
                ->nullOnDelete();
            // 個人覆寫:套用模板後,某個 menu 額外調整時存這
            // null = 沒有覆寫(完全跟模板)
            $table->json('permission_overrides_json')->nullable()->after('permission_template_id');
            // make_admin flag — 由 admin user 在 UI 勾選,儲存時把 linked user 提升為 admin
            $table->boolean('is_admin_promoted')->default(false)->after('permission_overrides_json');
        });

        // backfill:system_role → 對應系統模板
        $map = [];
        foreach (DB::table('permission_templates')->where('is_system', true)->get() as $tpl) {
            // 用模板名稱反查 role key(中文名跟 ROLE_PERMISSIONS 對齊)
            $key = match ($tpl->name) {
                '店家擁有者' => 'owner',
                '店長' => 'manager',
                '副店長' => 'sub_manager',
                '員工' => 'staff',
                default => null,
            };
            if ($key) $map[$key] = $tpl->id;
        }

        foreach ($map as $role => $tplId) {
            DB::table('employees')
                ->where('system_role', $role)
                ->whereNull('permission_template_id')
                ->update(['permission_template_id' => $tplId, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['permission_template_id']);
            $table->dropColumn(['permission_template_id', 'permission_overrides_json', 'is_admin_promoted']);
        });
    }
};
