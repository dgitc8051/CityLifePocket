<?php

namespace App\Providers;

use App\Support\Tenancy\IndirectShopScope;
use App\Support\Tenancy\ShopScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // 全域 Eloquent Builder macro:讓任何 model(包含沒有 trait 的 Shop / User / Organization)
        // 都能安全呼叫 ->withoutShopScope() 不會炸 "undefined method"。
        // withoutGlobalScope 對沒套用過的 scope 是 no-op,所以一次呼叫兩個 scope 是安全的。
        Builder::macro('withoutShopScope', function () {
            /** @var Builder $this */
            return $this
                ->withoutGlobalScope(ShopScope::class)
                ->withoutGlobalScope(IndirectShopScope::class);
        });
    }
}
