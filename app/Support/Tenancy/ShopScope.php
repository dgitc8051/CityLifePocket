<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Eloquent global scope:自動以 TenantContext::currentShopId() 過濾 shop_id 欄位。
 *
 * 行為:
 *  - 沒有 current shop(CLI / 未登入 / bypass)→ 不加 where,等同無 scope
 *  - 有 current shop → 加 where shop_id = ?
 *  - controller / query 想跨店 → 呼叫 ->withoutShopScope() 或 TenantContext::bypass()
 *
 * 不直接做 admin 自動 bypass,避免「admin 切到單店但意外撈到全部」的視覺資安漏洞。
 */
class ShopScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $shopId = TenantContext::currentShopId();
        if ($shopId === null) {
            return;
        }
        $builder->where($model->qualifyColumn('shop_id'), $shopId);
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutShopScope', function (Builder $b) {
            return $b->withoutGlobalScope(self::class);
        });

        $builder->macro('forShop', function (Builder $b, int $shopId) {
            return $b->withoutGlobalScope(self::class)
                ->where($b->getModel()->qualifyColumn('shop_id'), $shopId);
        });
    }
}
