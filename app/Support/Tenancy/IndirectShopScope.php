<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * 給「沒有自己的 shop_id 欄位,但能透過某個關聯走到 shop」的 model 用。
 *
 * 例:
 *   AttendanceRecord → employee → shop
 *   ScheduleEntry    → schedule → shop
 *   ShiftSwapRequest → fromEmployee → shop
 *
 * 用法:model 套 IndirectBelongsToShop trait 並定義 $shopVia / shopVia()。
 */
class IndirectShopScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $shopId = TenantContext::currentShopId();
        if ($shopId === null) return;

        /** @var string $relation */
        $relation = $model->shopVia();
        if (! $relation) return;

        // 內層 whereHas 把對應的 ShopScope 拿掉避免雙重過濾誤差,
        // 我們在這邊明確指定 shop_id,行為更可預期。
        $builder->whereHas($relation, function (Builder $q) use ($shopId) {
            $q->withoutGlobalScope(ShopScope::class)
                ->where($q->getModel()->qualifyColumn('shop_id'), $shopId);
        });
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutShopScope', function (Builder $b) {
            return $b->withoutGlobalScope(self::class);
        });

        $builder->macro('forShop', function (Builder $b, int $shopId) {
            $model = $b->getModel();
            $relation = method_exists($model, 'shopVia') ? $model->shopVia() : null;
            $b = $b->withoutGlobalScope(self::class);
            if ($relation) {
                // 同樣要把內層關聯的 ShopScope 拿掉,否則跨 shop 查詢必為 0
                $b->whereHas($relation, function (Builder $q) use ($shopId) {
                    $q->withoutGlobalScope(ShopScope::class)
                        ->where($q->getModel()->qualifyColumn('shop_id'), $shopId);
                });
            }
            return $b;
        });
    }
}
