<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_salary_multipliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            // 顯示用：例如「平日1.34倍」「假日2倍」
            $table->string('label');

            // 倍率：例如 1.34、1.67、2.00
            $table->decimal('multiplier', 5, 2);

            // 套用條件：weekday_ot / rest_day_ot / holiday / night / custom
            // 系統靠這個分類；店家可改 label 但 condition_type 決定怎麼算
            $table->enum('condition_type', [
                'weekday_ot', 'rest_day_ot', 'holiday', 'night', 'custom',
            ])->default('weekday_ot');

            // 條件細節 JSON：例如 {"hours_from":0,"hours_to":2}（前2小時）
            // 或 {"hours_from":2,"hours_to":4}（第3-4小時）
            // 或 {"start":"22:00","end":"06:00"}（夜間時段）
            $table->json('condition_json')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['shop_id', 'is_active', 'sort_order']);
        });

        // Seed 預設台灣勞基法 6 種倍率給每個現有店家
        // 店家可在設定頁自行修改 / 刪除 / 新增
        $shopIds = DB::table('shops')->pluck('id');
        $now = now();
        $defaults = [
            ['label' => '平日1.34倍', 'multiplier' => 1.34, 'condition_type' => 'weekday_ot',
                'condition_json' => json_encode(['hours_from' => 0, 'hours_to' => 2]), 'sort_order' => 10],
            ['label' => '平日1.67倍', 'multiplier' => 1.67, 'condition_type' => 'weekday_ot',
                'condition_json' => json_encode(['hours_from' => 2, 'hours_to' => 4]), 'sort_order' => 20],
            ['label' => '休息日1.34倍', 'multiplier' => 1.34, 'condition_type' => 'rest_day_ot',
                'condition_json' => json_encode(['hours_from' => 0, 'hours_to' => 2]), 'sort_order' => 30],
            ['label' => '休息日1.67倍', 'multiplier' => 1.67, 'condition_type' => 'rest_day_ot',
                'condition_json' => json_encode(['hours_from' => 2, 'hours_to' => 8]), 'sort_order' => 40],
            ['label' => '休息日2.67倍', 'multiplier' => 2.67, 'condition_type' => 'rest_day_ot',
                'condition_json' => json_encode(['hours_from' => 8, 'hours_to' => 12]), 'sort_order' => 50],
            ['label' => '假日2倍', 'multiplier' => 2.00, 'condition_type' => 'holiday',
                'condition_json' => null, 'sort_order' => 60],
        ];
        foreach ($shopIds as $shopId) {
            foreach ($defaults as $d) {
                DB::table('shop_salary_multipliers')->insert(array_merge($d, [
                    'shop_id' => $shopId,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_salary_multipliers');
    }
};
