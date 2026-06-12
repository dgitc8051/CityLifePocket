<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Models\Shop;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $this->resolveShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $query = Employee::where('shop_id', $shop->id);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $employees = $query
            ->with('stations:id,name,color')
            ->orderBy('status')
            ->orderByDesc('skill_score')
            ->get();

        return response()->json([
            'data' => $employees->map(fn ($e) => $this->transform($e)),
            'meta' => [
                'total' => $employees->count(),
                'active' => $employees->where('status', 'active')->count(),
            ],
        ]);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $shop = $this->resolveShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        // SaaS 配額:新增前先檢查方案上限(active employee 數)
        $org = $shop->organization;
        if ($org) {
            $org->assertCanAddSeat();  // 超出 → 拋 QuotaExceededException → 402 JSON
        }

        $validated = $request->validated();
        $stationIds = $validated['station_ids'] ?? [];
        $permOverrides = $validated['permissions_json'] ?? null;
        $makeAdmin = (bool) ($validated['make_admin'] ?? false);
        unset($validated['station_ids'], $validated['permissions_json'], $validated['make_admin']);

        // 防止非 admin 自行提升別人成 admin
        if ($makeAdmin && ! $request->user()->isAdmin()) {
            return response()->json(['error' => '只有最高管理員能授予最高管理員權限'], 403);
        }

        $employee = Employee::create([
            ...$validated,
            'shop_id' => $shop->id,
            'binding_level' => 'L0',
            'permission_overrides_json' => $permOverrides ?: null,
            'is_admin_promoted' => $makeAdmin,
        ]);

        if (! empty($stationIds)) {
            $employee->stations()->sync($stationIds);
        }

        $this->syncPermissionsToUser($employee);

        AuditService::log('employee.create', $employee, null, $employee->toArray());

        return response()->json(['data' => $this->transform($employee->fresh('stations'))], 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json(['data' => $this->transform($employee)]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $user = $request->user();
        $before = $employee->toArray();
        $validated = $request->validated();
        $stationIds = $validated['station_ids'] ?? null;
        $permOverrides = array_key_exists('permissions_json', $validated) ? $validated['permissions_json'] : '__no_change__';
        $makeAdmin = array_key_exists('make_admin', $validated) ? (bool) $validated['make_admin'] : null;
        unset($validated['station_ids'], $validated['permissions_json'], $validated['make_admin']);

        // 防止非 admin 自行提升別人成 admin
        if ($makeAdmin === true && ! $user->isAdmin()) {
            return response()->json(['error' => '只有最高管理員能授予最高管理員權限'], 403);
        }
        // 防止非 admin 撤掉一個 admin 的 admin 旗標(避免互踢)
        if ($makeAdmin === false && $employee->is_admin_promoted && ! $user->isAdmin()) {
            return response()->json(['error' => '只有最高管理員能撤銷最高管理員權限'], 403);
        }

        if ($permOverrides !== '__no_change__') {
            $validated['permission_overrides_json'] = $permOverrides ?: null;
        }
        if ($makeAdmin !== null) {
            $validated['is_admin_promoted'] = $makeAdmin;
        }

        // line_user_id 鎖死邏輯：已綁過要改 → 只有 admin 可以
        if (array_key_exists('line_user_id', $validated)) {
            $newVal = $validated['line_user_id'];
            $oldVal = $employee->line_user_id;
            $changing = ($newVal !== $oldVal);
            $hadValue = ! empty($oldVal);

            if ($changing && $hadValue && ! $user->isAdmin()) {
                return response()->json([
                    'error' => '此員工的 LINE 帳號已綁定，只有最高管理員可以變更或清除。',
                ], 403);
            }

            // admin 清空綁定時，也清掉 user_id（讓員工下次 LINE 登入可重新走綁定流程）
            if ($changing && empty($newVal) && $user->isAdmin()) {
                $validated['user_id'] = null;
            }
        }

        $employee->update($validated);
        if ($stationIds !== null) {
            $employee->stations()->sync($stationIds);
        }

        $this->syncPermissionsToUser($employee);

        AuditService::log('employee.update', $employee, $before, $employee->toArray());

        return response()->json(['data' => $this->transform($employee->fresh('stations'))]);
    }

    /**
     * 員工綁了 User 帳號時,把 employee 上設定的三個東西同步到 user:
     *   - is_admin_promoted = true  → user.role = 'admin'(短路全部)
     *   - permission_template_id    → user.permission_template_id
     *   - permission_overrides_json → user.permissions_json
     *   - system_role(legacy)        → user.role
     *
     * 沒綁 user 的話,先存在 employee 上,等之後 LINE 綁定時自動套用。
     */
    private function syncPermissionsToUser(Employee $employee): void
    {
        // 先試著從 line_user_id 找對應 User 並自動 link
        $this->autoBindUserFromLineId($employee);

        if (! $employee->user_id) return;
        $user = \App\Models\User::find($employee->user_id);
        if (! $user) return;

        $updates = [];

        // admin promotion 是強制的(設 true → role=admin;設 false 但原本是 admin → 降回 system_role)
        if ($employee->is_admin_promoted) {
            $updates['role'] = 'admin';
            // admin 不需要模板/覆寫;清掉以免被當作普通使用者解析
            $updates['permission_template_id'] = null;
            $updates['permissions_json'] = null;
        } else {
            // 不是 admin promotion:
            //   role 用 system_role(legacy,給 isOwner/isManager 之類仍依賴 role 的地方繼續用)
            //   template/overrides 直接從 employee 同步
            $updates['permission_template_id'] = $employee->permission_template_id;
            $updates['permissions_json'] = $employee->permission_overrides_json;

            if ($employee->system_role && $user->role !== $employee->system_role) {
                $updates['role'] = $employee->system_role;
            }
            // 如果 user 原本是 admin 但 employee.is_admin_promoted=false → 降回 system_role
            if ($user->role === 'admin' && ! $employee->is_admin_promoted) {
                $updates['role'] = $employee->system_role ?? 'staff';
            }
        }

        if (! empty($updates)) {
            $user->update($updates);
        }
    }

    /**
     * 員工填了 line_user_id，但還沒有 user_id 時：
     * 找對應的 User（用 LINE 登入過會自動建立），連結到此 employee。
     * 這就是「店長綁定 LINE 員工」的核心邏輯。
     */
    private function autoBindUserFromLineId(Employee $employee): void
    {
        if (! $employee->line_user_id) return;
        if ($employee->user_id) return; // 已綁過

        $user = \App\Models\User::where('line_user_id', $employee->line_user_id)->first();
        if (! $user) return; // 員工還沒用 LINE 登入過，不做事

        $employee->user_id = $user->id;
        $employee->saveQuietly();
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $before = $employee->toArray();
        $employee->update([
            'status' => 'terminated',
            'leave_date' => now()->toDateString(),
        ]);
        AuditService::log('employee.terminate', $employee, $before, $employee->toArray());

        return response()->json(['message' => 'Employee terminated']);
    }

    private function resolveShop(): ?Shop
    {
        return Auth::user()?->resolveCurrentShop();
    }

    private function transform(Employee $e): array
    {
        return [
            'id' => $e->id,
            'name' => $e->name,
            'phone' => $e->phone,
            'birthday' => $e->birthday?->toDateString(),
            'line_user_id' => $e->line_user_id,
            'user_id' => $e->user_id,
            'binding_level' => $e->binding_level,
            'skill_score' => $e->skill_score,
            'level' => $e->level,
            'level_label' => match ($e->level) {
                'lead' => '領班',
                'senior' => '熟手',
                'junior' => '初階',
                'trainee' => '新手',
                default => '?',
            },
            'system_role' => $e->system_role ?? 'staff',
            'system_role_label' => match ($e->system_role ?? 'staff') {
                'owner' => '老闆',
                'manager' => '店長',
                'sub_manager' => '副店長',
                'staff' => '員工',
                default => '員工',
            },
            'permission_template_id' => $e->permission_template_id,
            'permission_overrides_json' => $e->permission_overrides_json,
            'is_admin_promoted' => (bool) $e->is_admin_promoted,
            'employment_type' => $e->employment_type,
            'employment_type_label' => match ($e->employment_type) {
                'full' => '全職',
                'part' => '兼職',
                'intern' => '實習',
                default => '?',
            },
            'hire_date' => $e->hire_date?->toDateString(),
            'leave_date' => $e->leave_date?->toDateString(),
            'status' => $e->status,
            'weekly_max_hours' => $e->weekly_max_hours,
            'weekly_min_hours' => $e->weekly_min_hours,
            'daily_max_hours' => $e->daily_max_hours,
            'hourly_wage' => $e->hourly_wage,
            'monthly_salary' => $e->monthly_salary,
            'notes' => $e->notes,
            'stations' => $e->relationLoaded('stations')
                ? $e->stations->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'color' => $s->color,
                    'is_primary' => (bool) ($s->pivot->is_primary ?? false),
                ])->values()
                : [],
            'station_ids' => $e->relationLoaded('stations')
                ? $e->stations->pluck('id')->values()
                : [],
        ];
    }
}
