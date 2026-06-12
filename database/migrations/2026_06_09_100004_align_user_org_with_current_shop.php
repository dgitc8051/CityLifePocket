<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 修正 backfill 留下的不一致:user 的 organization_id 跟其 current_shop 的 organization_id 不同。
 *
 * 原因:backfill 是以 shop owner 為單位開組織,LINE-only / employee-only 的 user
 * 會被丟到「未分派組織」,但他們的 current_shop_id 指向另一個 org 的 shop。
 *
 * 修正策略:如果 user.current_shop 存在,user.org 對齊 shop.org(以實際工作的店為準)。
 * 修正完後,「未分派組織」若沒人剩下就軟刪除。
 */
return new class extends Migration {
    public function up(): void
    {
        $mismatches = DB::table('users as u')
            ->join('shops as s', 's.id', '=', 'u.current_shop_id')
            ->whereColumn('u.organization_id', '!=', 's.organization_id')
            ->orWhere(function ($q) {
                $q->whereNotNull('u.current_shop_id')->whereNull('u.organization_id');
            })
            ->select('u.id as user_id', 's.organization_id as target_org_id', 'u.organization_id as current_org_id')
            ->get();

        foreach ($mismatches as $row) {
            DB::table('users')
                ->where('id', $row->user_id)
                ->update(['organization_id' => $row->target_org_id, 'updated_at' => now()]);
        }

        // 軟刪除已經沒人也沒店的「未分派組織」
        $unassigned = DB::table('organizations')
            ->where('slug', 'like', 'unassigned-%')
            ->get();

        foreach ($unassigned as $org) {
            $userCount = DB::table('users')->where('organization_id', $org->id)->count();
            $shopCount = DB::table('shops')->where('organization_id', $org->id)->count();
            if ($userCount === 0 && $shopCount === 0) {
                DB::table('organizations')->where('id', $org->id)->update([
                    'deleted_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // 此資料修正不可逆 — 故意空著
    }
};
