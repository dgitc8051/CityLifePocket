<?php

namespace App\Support\Tenancy;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 套用在「直接有 shop_id 欄位」的 model 上。
 *
 * 功能:
 *  1. 註冊 ShopScope 全域 scope → 所有查詢自動加 where shop_id = current
 *  2. creating event → 若沒填 shop_id 自動帶入當前 shop
 *  3. 提供 shop() relation(若 model 還沒定義)
 *
 * 不適用:
 *  - 透過關聯間接屬於 shop 的 model(例:AttendanceRecord 是透過 employee_id → shop_id)
 *    那種 model 之後加 shop_id 冗餘欄位或寫 IndirectShopScope。
 */
trait BelongsToShop
{
    public static function bootBelongsToShop(): void
    {
        static::addGlobalScope(new ShopScope);

        static::creating(function ($model) {
            if (! $model->getAttribute('shop_id')) {
                $shopId = TenantContext::currentShopId();
                if ($shopId) {
                    $model->setAttribute('shop_id', $shopId);
                }
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
