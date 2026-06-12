<?php

namespace App\Support\Tenancy;

/**
 * Model 透過某個關聯間接屬於 shop 時用這個 trait。
 *
 * 需求:套用的 model 必須有 protected string $shopVia 屬性,
 * 值為走到 shop_id 的關聯方法名(例:'employee' 表示 employee()->shop_id)。
 *
 * 若需要更複雜邏輯,可以覆寫 shopVia() 方法。
 */
trait IndirectBelongsToShop
{
    public static function bootIndirectBelongsToShop(): void
    {
        static::addGlobalScope(new IndirectShopScope);
    }

    public function shopVia(): string
    {
        return $this->shopVia ?? '';
    }
}
