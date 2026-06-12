<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password',
        'organization_id',
        'line_user_id', 'phone', 'role', 'avatar_url', 'current_shop_id',
        'permissions_json', 'permission_template_id',
    ];

    /**
     * 所有 menu permission keys(權限矩陣的 row)。
     * 加新功能時記得補。前端權限矩陣也讀這個清單(透過 API)。
     */
    public const MENU_KEYS = [
        'dashboard', 'schedule', 'shift_templates', 'availability',
        'employees', 'leaves', 'shift_swaps', 'coverage',
        'attendance', 'reports', 'payroll',
        'settings', 'audit_logs', 'permission_templates',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions_json' => 'array',
        ];
    }

    /**
     * 各角色預設權限。
     * 值: 'rw' | 'r' | 'none'
     */
    public const ROLE_PERMISSIONS = [
        'admin' => [
            'dashboard' => 'rw', 'schedule' => 'rw', 'shift_templates' => 'rw',
            'availability' => 'rw', 'employees' => 'rw', 'leaves' => 'rw',
            'shift_swaps' => 'rw', 'attendance' => 'rw', 'reports' => 'rw', 'settings' => 'rw', 'audit_logs' => 'rw',
        ],
        'owner' => [
            'dashboard' => 'rw', 'schedule' => 'rw', 'shift_templates' => 'rw',
            'availability' => 'rw', 'employees' => 'rw', 'leaves' => 'rw',
            'shift_swaps' => 'rw', 'attendance' => 'rw', 'reports' => 'rw', 'settings' => 'rw', 'audit_logs' => 'rw',
        ],
        'manager' => [
            'dashboard' => 'rw', 'schedule' => 'rw', 'shift_templates' => 'rw',
            'availability' => 'rw', 'employees' => 'rw', 'leaves' => 'rw',
            'shift_swaps' => 'rw', 'attendance' => 'rw', 'reports' => 'rw', 'settings' => 'rw', 'audit_logs' => 'r',
        ],
        'sub_manager' => [
            'dashboard' => 'rw', 'schedule' => 'rw', 'shift_templates' => 'r',
            'availability' => 'rw', 'employees' => 'r', 'leaves' => 'rw',
            'shift_swaps' => 'rw', 'attendance' => 'rw', 'reports' => 'r', 'settings' => 'none', 'audit_logs' => 'none',
        ],
        'staff' => [
            'dashboard' => 'r', 'schedule' => 'r', 'shift_templates' => 'none',
            'availability' => 'rw', 'employees' => 'none', 'leaves' => 'rw',
            'shift_swaps' => 'rw', 'attendance' => 'rw', 'reports' => 'none', 'settings' => 'none', 'audit_logs' => 'none',
        ],
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function currentShop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'current_shop_id');
    }

    public function ownedShops(): HasMany
    {
        return $this->hasMany(Shop::class, 'owner_user_id');
    }

    /**
     * 此使用者在自己組織內可看到的所有 shop(admin 看全部)。
     * 用於切換店面、跨店報表、權限檢查的 baseline。
     *
     * 注意:Shop 沒有 shop_id 欄位,不受 ShopScope 影響,可直接 query。
     */
    public function accessibleShops()
    {
        if ($this->isAdmin()) {
            return Shop::query();
        }
        return Shop::query()->where('organization_id', $this->organization_id);
    }

    public function canManageOrganization(int $organizationId): bool
    {
        if ($this->isAdmin()) return true;
        return $this->organization_id === $organizationId && $this->isOwner();
    }

    public function canManageBrand(int $brandId): bool
    {
        if ($this->isAdmin()) return true;
        $brand = Brand::find($brandId);
        return $brand && $brand->organization_id === $this->organization_id && $this->isOwner();
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isManager(): bool
    {
        return in_array($this->role, ['admin', 'owner', 'manager', 'sub_manager'], true);
    }

    public function canManageShop(int $shopId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        if ($this->isOwner() && $this->ownedShops()->where('id', $shopId)->exists()) {
            return true;
        }
        return $this->isManager() && $this->current_shop_id === $shopId;
    }

    /**
     * 解析此 user 的有效權限。
     *
     * 優先序:
     *   1. admin:全部 rw(忽略 template / json — 最高管理員不能被降權)
     *   2. permission_template_id:該模板的 permissions_json
     *      其上再疊 permissions_json(個人覆寫)
     *   3. 沒有模板:permissions_json 直接生效
     *   4. 兩者皆無:fallback 到舊的 ROLE_PERMISSIONS(向後相容)
     */
    public function getPermissions(): array
    {
        if ($this->isAdmin()) {
            return array_fill_keys(self::MENU_KEYS, 'rw');
        }

        $template = $this->permission_template_id
            ? PermissionTemplate::find($this->permission_template_id)
            : null;

        $base = $template?->permissions_json
            ?? self::ROLE_PERMISSIONS[$this->role]    // 舊 user(template 還沒指)走 fallback
            ?? [];

        $overrides = is_array($this->permissions_json) ? $this->permissions_json : [];

        return array_merge($base, $overrides);
    }

    public function hasPermission(string $key, string $level = 'r'): bool
    {
        if ($this->isAdmin()) return true;          // 短路:admin 永遠通過

        $perms = $this->getPermissions();
        $value = $perms[$key] ?? 'none';
        if ($value === 'rw') return true;
        if ($value === 'r') return $level === 'r';
        return false;
    }

    public function canWrite(string $key): bool
    {
        return $this->hasPermission($key, 'rw');
    }

    public function permissionTemplate(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PermissionTemplate::class);
    }

    /**
     * 解析此使用者「目前操作」的 Shop。
     *
     * 邏輯(含 org 邊界):
     * 1. 有設 current_shop_id 且 shop 在自己 org → 用該店
     * 2. 否則用自己 org 下擁有的第一家店
     * 3. 否則用自己 employee 綁到的 shop
     * 4. admin: fallback 到全系統第一家店(他可跨 org)
     *
     * 絕對不會回傳跨 org 的 shop(避免 tenant 滲漏)。
     */
    public function resolveCurrentShop(): ?Shop
    {
        if ($this->current_shop_id) {
            $shop = Shop::find($this->current_shop_id);
            if ($shop && ($this->isAdmin() || $shop->organization_id === $this->organization_id)) {
                return $shop;
            }
        }

        if ($this->organization_id) {
            $owned = $this->ownedShops()->where('organization_id', $this->organization_id)->first();
            if ($owned) return $owned;

            $linked = Shop::query()
                ->where('organization_id', $this->organization_id)
                ->whereIn('id', $this->employees()->pluck('shop_id'))
                ->first();
            if ($linked) return $linked;
        }

        if ($this->isAdmin()) {
            return Shop::query()->first();
        }

        return null;
    }
}
