<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\OncallSchedule;
use App\Models\RoutingRule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class FixFlowSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Teams ─── //
        $hvacTeam = Team::create(['name' => '空調維修組', 'slug' => 'hvac', 'type' => 'equipment']);
        $elevatorTeam = Team::create(['name' => '電梯維修組', 'slug' => 'elevator', 'type' => 'equipment']);
        $generalTeam = Team::create(['name' => '一般維修組', 'slug' => 'general', 'type' => 'equipment']);
        $billingTeam = Team::create(['name' => 'Billing Team', 'slug' => 'billing', 'type' => 'software']);
        $authTeam = Team::create(['name' => 'Auth Team', 'slug' => 'auth', 'type' => 'software']);

        // ─── Users ─── //
        $admin = User::create([
            'name' => '系統管理員',
            'email' => 'admin@fixflow.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $lead = User::create([
            'name' => '陳大華',
            'email' => 'lead@fixflow.test',
            'password' => Hash::make('password'),
            'role' => 'lead',
        ]);

        $manager = User::create([
            'name' => '林經理',
            'email' => 'manager@fixflow.test',
            'password' => Hash::make('password'),
            'role' => 'manager',
        ]);

        $tech1 = User::create([
            'name' => '王師傅',
            'email' => 'wang@fixflow.test',
            'password' => Hash::make('password'),
            'role' => 'technician',
        ]);

        $tech2 = User::create([
            'name' => '李師傅',
            'email' => 'lee@fixflow.test',
            'password' => Hash::make('password'),
            'role' => 'technician',
        ]);

        $eng1 = User::create([
            'name' => '張工程師',
            'email' => 'zhang@fixflow.test',
            'password' => Hash::make('password'),
            'role' => 'engineer',
        ]);

        $eng2 = User::create([
            'name' => '黃工程師',
            'email' => 'huang@fixflow.test',
            'password' => Hash::make('password'),
            'role' => 'engineer',
        ]);

        // ─── Team Members ─── //
        $hvacTeam->members()->attach([$tech1->id, $tech2->id, $lead->id]);
        $elevatorTeam->members()->attach([$tech2->id, $lead->id]);
        $generalTeam->members()->attach([$tech1->id, $tech2->id]);
        $billingTeam->members()->attach([$eng1->id, $lead->id]);
        $authTeam->members()->attach([$eng2->id, $lead->id]);

        // ─── Assets (Equipment) ─── //
        $assets = [
            ['asset_number' => 'A-001', 'name' => 'A棟1F大廳冷氣', 'type' => 'equipment', 'category' => 'hvac', 'location' => 'A棟 1 樓大廳', 'model' => '大金 RXV60UVLT', 'qr_code' => 'A-001', 'team_id' => $hvacTeam->id, 'installed_at' => '2023-06-15'],
            ['asset_number' => 'A-002', 'name' => 'A棟3F走廊冷氣', 'type' => 'equipment', 'category' => 'hvac', 'location' => 'A棟 3 樓走廊', 'model' => '大金 RXV40UVLT', 'qr_code' => 'A-002', 'team_id' => $hvacTeam->id, 'installed_at' => '2023-06-15'],
            ['asset_number' => 'B-001', 'name' => 'B棟3F大廳冷氣', 'type' => 'equipment', 'category' => 'hvac', 'location' => 'B棟 3 樓大廳', 'model' => '大金 RXV60UVLT', 'qr_code' => 'B-001', 'team_id' => $hvacTeam->id, 'installed_at' => '2023-08-20'],
            ['asset_number' => 'EL-001', 'name' => 'A棟電梯 #1', 'type' => 'equipment', 'category' => 'elevator', 'location' => 'A棟', 'model' => '三菱 NEXIEZ-MR', 'qr_code' => 'EL-001', 'team_id' => $elevatorTeam->id, 'installed_at' => '2022-01-10'],
            ['asset_number' => 'EL-002', 'name' => 'B棟電梯 #1', 'type' => 'equipment', 'category' => 'elevator', 'location' => 'B棟', 'model' => '三菱 NEXIEZ-MR', 'qr_code' => 'EL-002', 'team_id' => $elevatorTeam->id, 'installed_at' => '2022-01-10'],
            ['asset_number' => 'LT-001', 'name' => 'A棟1F走廊燈具', 'type' => 'equipment', 'category' => 'lighting', 'location' => 'A棟 1 樓走廊', 'model' => 'Philips LED Panel', 'qr_code' => 'LT-001', 'team_id' => $generalTeam->id, 'installed_at' => '2024-03-01'],
        ];

        foreach ($assets as $asset) {
            Asset::create($asset);
        }

        // ─── Assets (Software) ─── //
        Asset::create(['asset_number' => 'SW-BILLING', 'name' => '付款系統', 'type' => 'software', 'category' => 'billing', 'qr_code' => 'SW-BILLING', 'team_id' => $billingTeam->id]);
        Asset::create(['asset_number' => 'SW-AUTH', 'name' => '登入驗證系統', 'type' => 'software', 'category' => 'auth', 'qr_code' => 'SW-AUTH', 'team_id' => $authTeam->id]);

        // ─── On-call Schedules (current week) ─── //
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();

        OncallSchedule::create(['team_id' => $hvacTeam->id, 'user_id' => $tech1->id, 'start_at' => $weekStart, 'end_at' => $weekEnd]);
        OncallSchedule::create(['team_id' => $elevatorTeam->id, 'user_id' => $tech2->id, 'start_at' => $weekStart, 'end_at' => $weekEnd]);
        OncallSchedule::create(['team_id' => $generalTeam->id, 'user_id' => $tech1->id, 'start_at' => $weekStart, 'end_at' => $weekEnd]);
        OncallSchedule::create(['team_id' => $billingTeam->id, 'user_id' => $eng1->id, 'start_at' => $weekStart, 'end_at' => $weekEnd]);
        OncallSchedule::create(['team_id' => $authTeam->id, 'user_id' => $eng2->id, 'start_at' => $weekStart, 'end_at' => $weekEnd]);

        // ─── Routing Rules ─── //
        RoutingRule::create(['category' => 'hvac', 'team_id' => $hvacTeam->id, 'priority_weight' => 0]);
        RoutingRule::create(['category' => 'elevator', 'team_id' => $elevatorTeam->id, 'priority_weight' => 2]);
        RoutingRule::create(['category' => 'lighting', 'team_id' => $generalTeam->id, 'priority_weight' => 0]);
        RoutingRule::create(['category' => 'billing', 'team_id' => $billingTeam->id, 'priority_weight' => 2]);
        RoutingRule::create(['category' => 'auth', 'team_id' => $authTeam->id, 'priority_weight' => 2]);
    }
}
