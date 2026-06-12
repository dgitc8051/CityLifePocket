<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PermissionTemplate;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PermissionTemplateController extends Controller
{
    /**
     * GET /api/permission-templates
     *
     * 回:當前 user 可看的所有模板(system + 自己 org 的)。
     * 同時回 menu_keys 給前端組矩陣用。
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        $templates = PermissionTemplate::query()
            ->where(function ($q) use ($user) {
                $q->where('is_system', true);
                if ($user->organization_id) {
                    $q->orWhere('organization_id', $user->organization_id);
                }
            })
            ->orderBy('is_system', 'desc')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $templates->map(fn ($t) => $this->transform($t))->values(),
            'menu_keys' => User::MENU_KEYS,
            'menu_labels' => $this->menuLabels(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user->organization_id) {
            return response()->json(['error' => '使用者未綁定組織'], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64',
                Rule::unique('permission_templates')
                    ->where(fn ($q) => $q->where('organization_id', $user->organization_id))
                    ->whereNull('deleted_at')],
            'description' => 'nullable|string|max:255',
            'permissions' => 'required|array',
            'permissions.*' => 'in:rw,r,none',
        ]);

        // 只接受有效的 menu key
        $permissions = collect($data['permissions'])
            ->only(User::MENU_KEYS)
            ->all();

        $tpl = PermissionTemplate::create([
            'organization_id' => $user->organization_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions_json' => $permissions,
            'is_system' => false,
            'sort_order' => 100,
        ]);

        AuditService::log('permission_template.create', $tpl, null, $tpl->toArray());

        return response()->json(['data' => $this->transform($tpl)], 201);
    }

    public function update(Request $request, PermissionTemplate $permissionTemplate): JsonResponse
    {
        $user = Auth::user();
        if ($permissionTemplate->is_system) {
            return response()->json(['error' => '系統內建模板不可修改,請「另存為」新模板'], 403);
        }
        if (! $user->isAdmin() && $permissionTemplate->organization_id !== $user->organization_id) {
            return response()->json(['error' => '此模板不屬於你的組織'], 403);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:64',
                Rule::unique('permission_templates')
                    ->where(fn ($q) => $q->where('organization_id', $permissionTemplate->organization_id))
                    ->ignore($permissionTemplate->id)
                    ->whereNull('deleted_at')],
            'description' => 'sometimes|nullable|string|max:255',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'in:rw,r,none',
        ]);

        $before = $permissionTemplate->toArray();
        if (isset($data['permissions'])) {
            $data['permissions_json'] = collect($data['permissions'])->only(User::MENU_KEYS)->all();
            unset($data['permissions']);
        }
        $permissionTemplate->update($data);

        AuditService::log('permission_template.update', $permissionTemplate, $before, $permissionTemplate->fresh()->toArray());

        return response()->json(['data' => $this->transform($permissionTemplate->fresh())]);
    }

    public function destroy(PermissionTemplate $permissionTemplate): JsonResponse
    {
        $user = Auth::user();
        if ($permissionTemplate->is_system) {
            return response()->json(['error' => '系統內建模板不可刪除'], 403);
        }
        if (! $user->isAdmin() && $permissionTemplate->organization_id !== $user->organization_id) {
            return response()->json(['error' => '此模板不屬於你的組織'], 403);
        }

        // 把使用此模板的 user 解除綁定(保留他們的 permissions_json 個人覆寫)
        $affected = User::where('permission_template_id', $permissionTemplate->id)
            ->update(['permission_template_id' => null]);

        $before = $permissionTemplate->toArray();
        $permissionTemplate->delete();
        AuditService::log('permission_template.delete', $permissionTemplate, $before, null);

        return response()->json(['message' => 'deleted', 'users_unbound' => $affected]);
    }

    /**
     * POST /api/permission-templates/{id}/apply
     * body: { employee_ids: [int,...] }(優先),或 { user_ids: [int,...] }(legacy)
     *
     * 把模板套到指定員工身上(同 org)。會同步:
     *   - employees.permission_template_id
     *   - 如果該員工有 linked user_id,也更新 users.permission_template_id
     *   - reset_overrides=true 時清掉個人覆寫(employee + user 都清)
     *
     * admin 員工(is_admin_promoted=true / users.role=admin)不會被套(略過)。
     */
    public function applyToUsers(Request $request, PermissionTemplate $permissionTemplate): JsonResponse
    {
        $user = Auth::user();
        if (! $permissionTemplate->visibleTo($user)) {
            return response()->json(['error' => '無權使用此模板'], 403);
        }

        $data = $request->validate([
            'employee_ids' => 'sometimes|array',
            'employee_ids.*' => 'integer|exists:employees,id',
            'user_ids' => 'sometimes|array',
            'user_ids.*' => 'integer|exists:users,id',
            'reset_overrides' => 'sometimes|boolean',
        ]);

        if (empty($data['employee_ids']) && empty($data['user_ids'])) {
            return response()->json(['error' => '請至少指定 employee_ids 或 user_ids'], 422);
        }

        $resetOverrides = ! empty($data['reset_overrides']);
        $tplId = $permissionTemplate->id;

        // 累計計數,Path A / Path B 共用
        $empAffected = 0;
        $userAffected = 0;

        // Path A: employee_ids
        if (! empty($data['employee_ids'])) {
            $empQuery = \App\Models\Employee::query()
                ->withoutShopScope()
                ->whereIn('id', $data['employee_ids'])
                ->where('is_admin_promoted', false);

            // 非 admin → 限自己 org 的店家底下的員工
            if (! $user->isAdmin()) {
                $shopIds = \App\Models\Shop::query()->withoutShopScope()
                    ->where('organization_id', $user->organization_id)
                    ->pluck('id');
                $empQuery->whereIn('shop_id', $shopIds);
            }

            $employees = $empQuery->get(['id', 'user_id']);
            $empAffected = $employees->count();

            if ($empAffected > 0) {
                $updateEmp = ['permission_template_id' => $tplId];
                if ($resetOverrides) $updateEmp['permission_overrides_json'] = null;
                \App\Models\Employee::query()->withoutShopScope()
                    ->whereIn('id', $employees->pluck('id'))
                    ->update($updateEmp);

                // 同步到 linked users
                $linkedUserIds = $employees->pluck('user_id')->filter()->values();
                if ($linkedUserIds->isNotEmpty()) {
                    $updateUser = ['permission_template_id' => $tplId];
                    if ($resetOverrides) $updateUser['permissions_json'] = null;
                    $userAffected += User::whereIn('id', $linkedUserIds)
                        ->where('role', '!=', 'admin')
                        ->update($updateUser);
                }
            }
        }

        // Path B: user_ids (legacy 路徑,純套到 user)
        if (! empty($data['user_ids'])) {
            $query = User::whereIn('id', $data['user_ids'])
                ->where('role', '!=', 'admin');
            if (! $user->isAdmin()) {
                $query->where('organization_id', $user->organization_id);
            }
            $updateUser = ['permission_template_id' => $tplId];
            if ($resetOverrides) $updateUser['permissions_json'] = null;
            $userAffected += $query->update($updateUser);
        }

        return response()->json([
            'message' => 'applied',
            'employees_updated' => $empAffected,
            'users_updated' => $userAffected,
        ]);
    }

    private function transform(PermissionTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'permissions' => $t->permissions_json ?? [],
            'is_system' => (bool) $t->is_system,
            'organization_id' => $t->organization_id,
            'sort_order' => $t->sort_order,
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    private function menuLabels(): array
    {
        return [
            'dashboard' => '今日概覽',
            'schedule' => '人員排班',
            'shift_templates' => '各班人力設定',
            'availability' => '排班意願',
            'employees' => '員工資料',
            'leaves' => '請假審核',
            'shift_swaps' => '換班申請',
            'coverage' => '換班市場',
            'attendance' => '出勤打卡',
            'reports' => '工時報表',
            'payroll' => '薪資 / 時數表',
            'settings' => '店家資料',
            'audit_logs' => '操作紀錄',
            'permission_templates' => '權限模板管理',
        ];
    }
}
