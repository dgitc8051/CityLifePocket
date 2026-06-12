<?php

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. shops 加 brand_id 預留欄位（先 nullable，不加 FK 因為 brands 表還沒建）
        Schema::table('shops', function (Blueprint $table) {
            if (! Schema::hasColumn('shops', 'brand_id')) {
                $table->unsignedBigInteger('brand_id')->nullable()->after('id');
                $table->index('brand_id');
            }
        });

        // 2. 回填 users.current_shop_id — 沒設定的話用：
        //    a) 自己擁有的第一家店（owner case）
        //    b) 否則用任一家店（過渡期，多店環境下會在 UI 切換）
        $firstShop = Shop::query()->first();
        foreach (User::whereNull('current_shop_id')->get() as $user) {
            $ownShop = $user->ownedShops()->first();
            $user->current_shop_id = $ownShop?->id ?? $firstShop?->id;
            if ($user->current_shop_id) {
                $user->saveQuietly();
            }
        }
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            if (Schema::hasColumn('shops', 'brand_id')) {
                $table->dropIndex(['brand_id']);
                $table->dropColumn('brand_id');
            }
        });
    }
};
