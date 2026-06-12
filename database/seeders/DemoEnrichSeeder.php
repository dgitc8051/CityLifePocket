<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeAvailability;
use App\Models\LeaveRequest;
use App\Models\ShiftTemplate;
use App\Models\Shop;
use App\Models\Station;
use App\Services\AutoScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 補齊現有 demo 資料：時薪、員工站別、時段站別需求、可上時段、請假、自動排班。
 * 設計為**冪等**（多次執行不會壞），會 skip 已存在的關聯。
 */
class DemoEnrichSeeder extends Seeder
{
    public function run(): void
    {
        $shop = Shop::query()->first();
        if (! $shop) {
            $this->command->error('找不到 Shop。請先跑 DemoSeeder。');
            return;
        }

        $this->command->info('開始補資料...');

        $this->seedHourlyWages();
        $this->seedEmployeeStations($shop->id);
        $this->seedShiftStationRequirements($shop->id);
        $this->seedAvailabilities($shop->id);
        $this->seedLeaves($shop->id);
        $this->seedAutoSchedule($shop);

        $this->command->info('✓ 補完。');
    }

    /** 1. 時薪：依等級給 */
    private function seedHourlyWages(): void
    {
        $wageByLevel = [
            'lead' => 250,
            'senior' => 220,
            'junior' => 190,
            'trainee' => 176, // 2026 基本時薪
        ];

        $updated = 0;
        foreach (Employee::where('status', 'active')->whereNull('hourly_wage')->get() as $emp) {
            $emp->hourly_wage = $wageByLevel[$emp->level] ?? 190;
            $emp->save();
            $updated++;
        }
        $this->command->line("  時薪：補了 {$updated} 位員工");
    }

    /** 2. 員工站別：依等級綁站 — 等級高的會比較多站 */
    private function seedEmployeeStations(int $shopId): void
    {
        $stations = Station::where('shop_id', $shopId)->orderBy('id')->get()->keyBy('name');
        if ($stations->isEmpty()) {
            $this->command->line('  站別：略過（shop 沒站別）');
            return;
        }

        // 按等級給「會做的站別」
        // 飲料店：收銀 / 製作 / 備料 / 外送
        $assignments = [
            'lead' => ['收銀', '製作', '備料', '外送'],    // 全會
            'senior' => ['收銀', '製作', '備料'],          // 不包外送
            'junior' => ['收銀', '製作'],                  // 前場
            'trainee' => ['備料'],                         // 新手只能備料
        ];

        $count = 0;
        foreach (Employee::where('shop_id', $shopId)->where('status', 'active')->get() as $emp) {
            $stationNames = $assignments[$emp->level] ?? ['備料'];
            $stationIds = collect($stationNames)
                ->map(fn ($n) => $stations[$n]->id ?? null)
                ->filter()
                ->all();
            if (empty($stationIds)) continue;

            // 用 sync 避免重複
            $emp->stations()->sync(array_fill_keys($stationIds, ['is_primary' => false]));
            // 把第一個設為 primary
            $emp->stations()->updateExistingPivot($stationIds[0], ['is_primary' => true]);
            $count++;
        }
        $this->command->line("  員工站別：綁了 {$count} 位");
    }

    /** 3. 時段站別需求：每個 active 時段都加上基本站別 */
    private function seedShiftStationRequirements(int $shopId): void
    {
        $stations = Station::where('shop_id', $shopId)->get()->keyBy('name');
        if ($stations->isEmpty()) return;

        // 各時段需要的站別配置
        $shiftStations = [
            '早1' => ['備料' => 1, '製作' => 1],
            '早2' => ['收銀' => 1, '製作' => 1],
            '中班' => ['收銀' => 1, '製作' => 1, '備料' => 1],
            '晚班' => ['收銀' => 1, '製作' => 1],
        ];

        $count = 0;
        foreach (ShiftTemplate::where('shop_id', $shopId)->where('is_active', true)->get() as $tpl) {
            $req = $shiftStations[$tpl->name] ?? null;
            if (! $req) continue;

            $payload = [];
            foreach ($req as $name => $min) {
                if (isset($stations[$name])) {
                    $payload[$stations[$name]->id] = ['min_count' => $min];
                }
            }
            $tpl->requiredStations()->sync($payload);
            $count++;
        }
        $this->command->line("  時段站別需求：設了 {$count} 個時段");
    }

    /** 4. 可上時段：本週 + 下週每位員工每天每時段都填（多數 available） */
    private function seedAvailabilities(int $shopId): void
    {
        $employees = Employee::where('shop_id', $shopId)->where('status', 'active')->get();
        $templates = ShiftTemplate::where('shop_id', $shopId)->where('is_active', true)->get();
        $thisWeek = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY);
        $nextWeek = $thisWeek->addWeek();

        $created = 0;
        foreach ([$thisWeek, $nextWeek] as $weekStart) {
            foreach ($employees as $i => $emp) {
                foreach (range(0, 6) as $dayOffset) {
                    $dow = $weekStart->addDays($dayOffset)->dayOfWeek;

                    foreach ($templates as $tpl) {
                        if (! ($tpl->days_of_week_bitmask & (1 << $dow))) continue;

                        // 用 hash 做確定性偽隨機，多次跑同樣 input 結果一樣
                        $hash = crc32("{$emp->id}|{$weekStart->toDateString()}|{$dow}|{$tpl->id}") % 100;

                        // 70% available, 20% maybe, 10% unavailable
                        $avail = $hash < 70 ? 'available' : ($hash < 90 ? 'maybe' : 'unavailable');

                        // trainee 整體比較少時段 (50% available)
                        if ($emp->level === 'trainee' && $hash < 50) {
                            $avail = 'unavailable';
                        }

                        EmployeeAvailability::updateOrCreate(
                            [
                                'employee_id' => $emp->id,
                                'week_start_date' => $weekStart->toDateString(),
                                'day_of_week' => $dow,
                                'shift_template_id' => $tpl->id,
                            ],
                            [
                                'availability' => $avail,
                                'source' => 'employee',
                                'submitted_at' => now(),
                            ],
                        );
                        $created++;
                    }
                }
            }
        }
        $this->command->line("  可上時段：upsert {$created} 筆（本週 + 下週）");
    }

    /** 5. 隨機請假：未來 2 週內幾筆 */
    private function seedLeaves(int $shopId): void
    {
        $employees = Employee::where('shop_id', $shopId)->where('status', 'active')->take(5)->get();
        if ($employees->isEmpty()) return;

        $today = CarbonImmutable::today();
        $samples = [
            ['emp' => 0, 'days' => 3, 'type' => 'personal', 'reason' => '家庭旅遊', 'status' => 'approved'],
            ['emp' => 1, 'days' => 5, 'type' => 'sick', 'reason' => '感冒發燒', 'status' => 'pending'],
            ['emp' => 2, 'days' => 8, 'type' => 'annual', 'reason' => '特休', 'status' => 'approved'],
            ['emp' => 3, 'days' => 10, 'type' => 'personal', 'reason' => '考試', 'status' => 'pending'],
        ];

        $created = 0;
        foreach ($samples as $s) {
            if (! isset($employees[$s['emp']])) continue;
            $emp = $employees[$s['emp']];
            $start = $today->addDays($s['days']);
            $end = $start->addHours(8);

            $exists = LeaveRequest::where('employee_id', $emp->id)
                ->whereDate('start_datetime', $start->toDateString())
                ->exists();
            if ($exists) continue;

            LeaveRequest::create([
                'employee_id' => $emp->id,
                'start_datetime' => $start->toDateTimeString(),
                'end_datetime' => $end->toDateTimeString(),
                'type' => $s['type'],
                'status' => $s['status'],
                'source' => 'employee',
                'reason' => $s['reason'],
                'submitted_at' => now(),
            ]);
            $created++;
        }
        $this->command->line("  請假：新增 {$created} 筆");
    }

    /** 6. 跑一次 AutoScheduler 把下週填好 */
    private function seedAutoSchedule(Shop $shop): void
    {
        $nextWeek = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY)->addWeek();

        $scheduler = new AutoScheduler($shop, $nextWeek->toDateString(), 'balanced', true);
        $result = $scheduler->generate();

        DB::transaction(function () use ($shop, $nextWeek, $result) {
            $schedule = \App\Models\Schedule::firstOrCreate(
                ['shop_id' => $shop->id, 'week_start_date' => $nextWeek->toDateString()],
                ['status' => 'draft', 'created_by_user_id' => $shop->owner_user_id],
            );

            \App\Models\ScheduleEntry::where('schedule_id', $schedule->id)->delete();

            foreach ($result['proposed'] as $row) {
                if (! empty($row['existing'])) continue;
                \App\Models\ScheduleEntry::create([
                    'schedule_id' => $schedule->id,
                    'employee_id' => $row['employee_id'],
                    'shift_template_id' => $row['shift_template_id'],
                    'date' => $row['date'],
                    'status' => 'scheduled',
                ]);
            }
        });

        $full = $result['summary']['slots_full'];
        $partial = $result['summary']['slots_partial'];
        $this->command->line("  下週班表：完整 {$full} / 不完整 {$partial}，建立 ".count($result['proposed'])." 項");
    }
}
