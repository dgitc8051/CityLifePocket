<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Shop;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (! Auth::attempt($credentials, true)) {
            throw ValidationException::withMessages([
                'email' => '帳號或密碼錯誤',
            ]);
        }

        $request->session()->regenerate();

        return response()->json([
            'user' => $this->transformUser($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['user' => null], 401);
        }

        return response()->json(['user' => $this->transformUser($user)]);
    }

    /**
     * 員工 LINE 登入後，輸入手機號碼完成綁定。
     *
     * 流程：
     * 1. 確認 user 有 line_user_id（剛 LINE 登入完）
     * 2. 找有 phone = 輸入值 + 在 current_shop 的 active employee
     * 3. 檢查該 employee 是否已被別人綁定
     * 4. 通過 → 綁 line_user_id + user_id 到該 employee
     * 5. 同步 employee.system_role 到 user.role
     */
    public function bindPhone(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->line_user_id) {
            return response()->json(['error' => '請先用 LINE 登入'], 401);
        }

        $data = $request->validate([
            'phone' => 'required|string|max:32',
        ]);

        $shopId = $user->current_shop_id ?? Shop::query()->first()?->id;
        if (! $shopId) {
            return response()->json(['error' => '找不到對應店家'], 404);
        }

        // 找對應 employee
        $employee = Employee::where('shop_id', $shopId)
            ->where('phone', $data['phone'])
            ->where('status', '!=', 'terminated')
            ->first();

        if (! $employee) {
            return response()->json([
                'error' => '查無此手機號碼的員工，請確認店長已建立你的員工資料',
            ], 404);
        }

        // 已被綁定 → 拒絕（除非綁的就是自己）
        if ($employee->line_user_id && $employee->line_user_id !== $user->line_user_id) {
            return response()->json([
                'error' => '此手機號碼已被其他 LINE 帳號綁定。如果是錯誤綁定，請聯絡管理員處理。',
            ], 409);
        }

        // 綁定
        $employee->line_user_id = $user->line_user_id;
        $employee->user_id = $user->id;
        $employee->save();

        // 同步 system_role 到 user.role（如果 user 還是 staff）
        if ($employee->system_role && in_array($user->role, ['staff'], true)) {
            $user->role = $employee->system_role;
            $user->save();
        }

        AuditService::log('user.bind_phone', $employee, null, [
            'employee_id' => $employee->id,
            'phone' => $data['phone'],
            'user_id' => $user->id,
        ], $shopId);

        return response()->json([
            'message' => '綁定成功',
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
            ],
            'user' => $this->transformUser($user->fresh()),
        ]);
    }

    /**
     * 切換 current_shop_id。目前單店環境下沒實質效果，
     * 但 brand SaaS 化後是核心入口。
     */
    public function switchShop(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['shop_id' => 'required|integer|exists:shops,id']);

        $shop = Shop::find($data['shop_id']);
        if (! $this->canAccessShop($user, $shop)) {
            return response()->json(['error' => '無權進入此店家'], 403);
        }

        $user->current_shop_id = $shop->id;
        $user->save();

        return response()->json(['user' => $this->transformUser($user->fresh())]);
    }

    /**
     * 判斷 user 是否能進這家店。
     *
     * Organization 邊界規則:
     * 1. admin 全可
     * 2. 必須是同一個 organization(其餘條件全部依此前提)
     * 3. 同 org 下:owner 全部可,manager 限自己 current,staff 限 employee 綁定的 shop
     */
    private function canAccessShop(User $user, ?Shop $shop): bool
    {
        if (! $shop) return false;
        if ($user->isAdmin()) return true;

        // org 邊界:不同組織直接拒絕
        if ($user->organization_id !== $shop->organization_id) {
            return false;
        }

        if ($user->isOwner()) return true;
        if ($user->ownedShops()->where('id', $shop->id)->exists()) return true;
        if ($user->employees()->where('shop_id', $shop->id)->exists()) return true;
        return false;
    }

    private function transformUser(User $user): array
    {
        // 不要做欄位投影:features() 需要 settings_json,缺欄位會 fallback 到「全開」的預設,
        // 造成前端 v-if="features.xxx" 全部評估為 true,UI 無法依店家設定隱藏功能。
        $user->loadMissing('currentShop');

        // 可進入的店家列表（單店時就一家；brand SaaS 後就是同品牌的所有店）
        $accessibleShops = $this->accessibleShopsFor($user);

        // 查當前店家的對應 employee（若有）
        $employee = null;
        if ($user->current_shop_id) {
            $employee = $user->employees()->where('shop_id', $user->current_shop_id)->first();
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'role_label' => match ($user->role) {
                'admin' => '系統管理員',
                'owner' => '店家擁有者',
                'manager' => '店長',
                'sub_manager' => '副店長',
                'staff' => '員工',
                default => $user->role,
            },
            'avatar_url' => $user->avatar_url,
            'line_user_id' => $user->line_user_id,
            'current_shop' => $user->currentShop ? [
                'id' => $user->currentShop->id,
                'name' => $user->currentShop->name,
                'brand_id' => $user->currentShop->brand_id,
                'features' => $user->currentShop->features(),
            ] : null,
            'accessible_shops' => $accessibleShops,
            'employee' => $employee ? [
                'id' => $employee->id,
                'name' => $employee->name,
                'level' => $employee->level,
                'status' => $employee->status,
            ] : null,
            'pending_binding' => $user->line_user_id && ! $employee && $user->role === 'staff',
            'permissions' => $user->getPermissions(),
            'permission_template' => $user->permission_template_id ? [
                'id' => $user->permission_template_id,
                'name' => optional(\App\Models\PermissionTemplate::find($user->permission_template_id))->name,
            ] : null,
            'has_permission_overrides' => ! empty($user->permissions_json),
        ];
    }

    /**
     * 可進入的店家清單(org 邊界內)。
     * - admin: 全部 shop(跨 org)
     * - owner: 自己 org 下所有 shop(連鎖加盟整組看到)
     * - 其他: org 內,自己 owned + employee 綁定的
     */
    private function accessibleShopsFor(User $user): array
    {
        // 用 withoutShopScope 避開全域 ShopScope,因為這就是要列「可切換的目標」
        if ($user->isAdmin()) {
            $shops = Shop::query()->withoutShopScope()->orderBy('name')->get(['id', 'name', 'brand_id', 'organization_id']);
        } elseif ($user->isOwner() && $user->organization_id) {
            $shops = Shop::query()->withoutShopScope()
                ->where('organization_id', $user->organization_id)
                ->orderBy('name')
                ->get(['id', 'name', 'brand_id', 'organization_id']);
        } else {
            $owned = $user->ownedShops()->get(['id', 'name', 'brand_id', 'organization_id']);
            $shopIds = $user->employees()->pluck('shop_id')->unique();
            $linkedShops = Shop::query()->withoutShopScope()
                ->whereIn('id', $shopIds)
                ->when($user->organization_id, fn ($q) => $q->where('organization_id', $user->organization_id))
                ->get(['id', 'name', 'brand_id', 'organization_id']);
            $shops = $owned->concat($linkedShops)->unique('id')->values();
        }

        // 過渡期:user 完全沒關聯但有 current_shop → 顯示一筆避免 UI 空白
        if ($shops->isEmpty() && $user->currentShop) {
            $shops = collect([$user->currentShop->only(['id', 'name', 'brand_id', 'organization_id'])]);
        }

        return $shops->map(fn ($s) => is_array($s) ? $s : [
            'id' => $s->id,
            'name' => $s->name,
            'brand_id' => $s->brand_id,
            'organization_id' => $s->organization_id,
        ])->values()->all();
    }
}
