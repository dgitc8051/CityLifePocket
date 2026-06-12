<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 把舊的 User::ROLE_PERMISSIONS 五個角色,轉成「系統內建模板」。
 *
 * 之後店家在 UI 上看到的選項:
 *   - 最高管理員(系統硬編,不走模板)
 *   - 店家擁有者 / 店長 / 副店長 / 員工 (系統模板,is_system=true)
 *   - 自訂...(該 org 自己建的)
 *
 * 同時把現有 user 的 role → permission_template_id 對應。
 */
return new class extends Migration {
    public function up(): void
    {
        $systemTemplates = [
            'owner' => [
                'name' => '店家擁有者',
                'description' => '完整存取所有功能(含店家設定、稽核)',
                'sort_order' => 10,
                'permissions' => [
                    'dashboard' => 'rw', 'schedule' => 'rw', 'shift_templates' => 'rw',
                    'availability' => 'rw', 'employees' => 'rw', 'leaves' => 'rw',
                    'shift_swaps' => 'rw', 'coverage' => 'rw',
                    'attendance' => 'rw', 'reports' => 'rw', 'payroll' => 'rw',
                    'settings' => 'rw', 'audit_logs' => 'rw',
                    'permission_templates' => 'rw',
                ],
            ],
            'manager' => [
                'name' => '店長',
                'description' => '排班、員工、請假、報表完整存取,稽核唯讀',
                'sort_order' => 20,
                'permissions' => [
                    'dashboard' => 'rw', 'schedule' => 'rw', 'shift_templates' => 'rw',
                    'availability' => 'rw', 'employees' => 'rw', 'leaves' => 'rw',
                    'shift_swaps' => 'rw', 'coverage' => 'rw',
                    'attendance' => 'rw', 'reports' => 'rw', 'payroll' => 'rw',
                    'settings' => 'rw', 'audit_logs' => 'r',
                    'permission_templates' => 'none',
                ],
            ],
            'sub_manager' => [
                'name' => '副店長',
                'description' => '日常排班、出勤、請假審核;不含店家設定與薪資',
                'sort_order' => 30,
                'permissions' => [
                    'dashboard' => 'rw', 'schedule' => 'rw', 'shift_templates' => 'r',
                    'availability' => 'rw', 'employees' => 'r', 'leaves' => 'rw',
                    'shift_swaps' => 'rw', 'coverage' => 'rw',
                    'attendance' => 'rw', 'reports' => 'r', 'payroll' => 'none',
                    'settings' => 'none', 'audit_logs' => 'none',
                    'permission_templates' => 'none',
                ],
            ],
            'staff' => [
                'name' => '員工',
                'description' => '自己的排班、可上時段、請假、換班、打卡',
                'sort_order' => 40,
                'permissions' => [
                    'dashboard' => 'r', 'schedule' => 'r', 'shift_templates' => 'none',
                    'availability' => 'rw', 'employees' => 'none', 'leaves' => 'rw',
                    'shift_swaps' => 'rw', 'coverage' => 'rw',
                    'attendance' => 'rw', 'reports' => 'none', 'payroll' => 'r',
                    'settings' => 'none', 'audit_logs' => 'none',
                    'permission_templates' => 'none',
                ],
            ],
        ];

        $idMap = [];
        foreach ($systemTemplates as $key => $tpl) {
            $idMap[$key] = DB::table('permission_templates')->insertGetId([
                'organization_id' => null,
                'name' => $tpl['name'],
                'description' => $tpl['description'],
                'permissions_json' => json_encode($tpl['permissions']),
                'is_system' => true,
                'sort_order' => $tpl['sort_order'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 把現有 user.role(非 admin)對應到模板
        foreach (['owner', 'manager', 'sub_manager', 'staff'] as $role) {
            if (! isset($idMap[$role])) continue;
            DB::table('users')
                ->where('role', $role)
                ->whereNull('permission_template_id')
                ->update(['permission_template_id' => $idMap[$role]]);
        }
    }

    public function down(): void
    {
        DB::table('users')->update(['permission_template_id' => null]);
        DB::table('permission_templates')->where('is_system', true)->delete();
    }
};
