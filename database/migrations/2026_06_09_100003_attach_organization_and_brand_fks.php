<?php

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        // 1. shops.organization_id
        Schema::table('shops', function (Blueprint $table) {
            if (! Schema::hasColumn('shops', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('id');
                $table->index('organization_id');
            }
        });

        // 2. users.organization_id
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('id');
                $table->index('organization_id');
            }
        });

        // 3. Backfill — 每個 shop owner 變成一個 Organization；該 owner 的所有 shop 歸到一個 Default Brand
        //    沒有 owner 的孤兒 shop → 全部丟到一個 "Unassigned" organization
        $this->backfill();

        // 4. 加 FK constraints（backfill 完才加，避免外鍵失敗）
        Schema::table('shops', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('brand_id')->references('id')->on('brands')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
        });
    }

    private function backfill(): void
    {
        // 收集所有 owner — 沒 owner 的 shop 用 sentinel 0
        $shopsByOwner = Shop::query()->get()->groupBy(fn ($s) => $s->owner_user_id ?? 0);

        foreach ($shopsByOwner as $ownerId => $shops) {
            $owner = $ownerId ? User::find($ownerId) : null;
            $orgName = $owner ? ($owner->name.'的組織') : '未分派組織';
            $slugBase = Str::slug($orgName) ?: 'org';
            $slug = $slugBase.'-'.uniqid('', false);

            // 建組織
            $orgId = DB::table('organizations')->insertGetId([
                'name' => $orgName,
                'slug' => $slug,
                'owner_user_id' => $ownerId ?: null,
                'plan' => 'starter',                              // 既有用戶當作 starter 等級
                'status' => 'active',
                'shop_quota' => max(10, $shops->count() + 5),     // 留點 buffer
                'seat_quota' => 100,
                'settings_json' => json_encode(['migrated_from_legacy' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 建 Default Brand（每個組織一個）
            $brandId = DB::table('brands')->insertGetId([
                'organization_id' => $orgId,
                'name' => $owner ? ($owner->name.'的品牌') : '預設品牌',
                'code' => 'default',
                'settings_json' => json_encode(['migrated_from_legacy' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 把該 owner 名下的 shop 全部歸到這個 org/brand
            DB::table('shops')
                ->whereIn('id', $shops->pluck('id'))
                ->update([
                    'organization_id' => $orgId,
                    'brand_id' => $brandId,
                ]);

            // 把該 owner 自己也歸到這個 organization
            if ($owner) {
                DB::table('users')->where('id', $owner->id)->update([
                    'organization_id' => $orgId,
                ]);
            }
        }

        // 把剩下沒 organization_id 的 user 都歸到「未分派組織」(若不存在就建一個)
        $orphanCount = DB::table('users')->whereNull('organization_id')->count();
        if ($orphanCount > 0) {
            $orphanOrgId = DB::table('organizations')->where('slug', 'like', 'wei-fen-pai-zu-zhi-%')->value('id')
                ?? DB::table('organizations')->insertGetId([
                    'name' => '未分派組織',
                    'slug' => 'unassigned-'.uniqid('', false),
                    'plan' => 'free',
                    'status' => 'active',
                    'shop_quota' => 1,
                    'seat_quota' => 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('users')->whereNull('organization_id')->update([
                'organization_id' => $orphanOrgId,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['brand_id']);
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
