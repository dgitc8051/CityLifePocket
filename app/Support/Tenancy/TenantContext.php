<?php

namespace App\Support\Tenancy;

use App\Models\Shop;
use Illuminate\Support\Facades\Auth;

/**
 * 全域目前 shop 上下文。預設從登入者 resolveCurrentShop() 拿,
 * 但 console、queue job、跨店報表、測試可以用 set()/forShop() 顯式覆寫。
 *
 * 設計重點:
 *  - 不從 session/request 讀,單純的 process-local state
 *  - 沒人登入時是 null,scope 自動跳過(CLI / seeding / factories 不會炸)
 */
class TenantContext
{
    /** 顯式覆寫的 shop id,優先於 Auth 解析 */
    protected static ?int $overrideShopId = null;

    /** 是否完全停用 scope(極少用,例如總部跨店報表) */
    protected static bool $bypass = false;

    public static function setShopId(?int $shopId): void
    {
        self::$overrideShopId = $shopId;
    }

    public static function currentShopId(): ?int
    {
        if (self::$bypass) return null;
        if (self::$overrideShopId !== null) return self::$overrideShopId;
        return Auth::user()?->current_shop_id ?? Auth::user()?->resolveCurrentShop()?->id;
    }

    public static function currentShop(): ?Shop
    {
        $id = self::currentShopId();
        return $id ? Shop::find($id) : null;
    }

    /**
     * 在 callback 內顯式跳過 tenant scope,結束後自動恢復。
     * 用於:跨店報表、總部後台、admin 視角。
     */
    public static function bypass(callable $cb): mixed
    {
        $prev = self::$bypass;
        self::$bypass = true;
        try {
            return $cb();
        } finally {
            self::$bypass = $prev;
        }
    }

    /**
     * 在 callback 內以指定 shop 操作,結束後自動恢復。
     * 用於:queue job、console command、cron。
     */
    public static function forShop(int $shopId, callable $cb): mixed
    {
        $prev = self::$overrideShopId;
        self::$overrideShopId = $shopId;
        try {
            return $cb();
        } finally {
            self::$overrideShopId = $prev;
        }
    }

    public static function isBypassed(): bool
    {
        return self::$bypass;
    }
}
