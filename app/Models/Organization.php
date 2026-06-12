<?php

namespace App\Models;

use App\Exceptions\QuotaExceededException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'owner_user_id',
        'plan', 'status', 'trial_ends_at', 'subscription_renews_at',
        'shop_quota', 'seat_quota', 'settings_json',
    ];

    protected $casts = [
        'settings_json' => 'array',
        'trial_ends_at' => 'datetime',
        'subscription_renews_at' => 'datetime',
        'shop_quota' => 'integer',
        'seat_quota' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    public function shops(): HasManyThrough
    {
        return $this->hasManyThrough(Shop::class, Brand::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing'], true);
    }

    public function shopCount(): int
    {
        return $this->shops()->count();
    }

    public function seatCount(): int
    {
        // 跨 shop 算員工總數;Employee 有 ShopScope,所以要 bypass
        return TenantContext::bypass(function () {
            return \App\Models\Employee::whereIn(
                'shop_id',
                $this->shops()->pluck('shops.id')
            )->where('status', 'active')->count();
        });
    }

    public function isOverShopQuota(): bool
    {
        return $this->shopCount() >= $this->shop_quota;
    }

    public function isOverSeatQuota(): bool
    {
        return $this->seatCount() >= $this->seat_quota;
    }

    public function assertCanAddShop(): void
    {
        if ($this->isOverShopQuota()) {
            throw QuotaExceededException::forShop($this->shopCount(), $this->shop_quota, $this->plan);
        }
    }

    public function assertCanAddSeat(): void
    {
        if ($this->isOverSeatQuota()) {
            throw QuotaExceededException::forSeat($this->seatCount(), $this->seat_quota, $this->plan);
        }
    }
}
