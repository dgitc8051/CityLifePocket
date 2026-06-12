<?php

namespace Database\Seeders;

use App\Models\AvailabilityCollectionSetting;
use App\Models\BusinessHour;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Schedule;
use App\Models\ScheduleEntry;
use App\Models\ShiftTemplate;
use App\Models\Shop;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // ---------- Owner / Manager user ----------
            $owner = User::updateOrCreate(
                ['email' => 'demo@shiftpal.local'],
                [
                    'name' => '阿明',
                    'password' => Hash::make('demo1234'),
                    'phone' => '0912000000',
                    'role' => 'owner',
                ],
            );

            // ---------- Shop ----------
            $shop = Shop::updateOrCreate(
                ['name' => '清新茶飲 信義店'],
                [
                    'owner_user_id' => $owner->id,
                    'timezone' => 'Asia/Taipei',
                    'settings_json' => ['leave_min_advance_days' => 3],
                ],
            );

            $owner->update(['current_shop_id' => $shop->id]);

            // ---------- Business hours (Mon-Sun 10:00-22:00) ----------
            for ($dow = 0; $dow <= 6; $dow++) {
                BusinessHour::updateOrCreate(
                    ['shop_id' => $shop->id, 'day_of_week' => $dow],
                    ['open_time' => '10:00:00', 'close_time' => '22:00:00', 'is_closed' => false],
                );
            }

            // ---------- Availability collection settings ----------
            AvailabilityCollectionSetting::updateOrCreate(
                ['shop_id' => $shop->id],
                [
                    'push_day_of_week' => 4,   // Thursday
                    'push_time' => '20:00:00',
                    'deadline_day_of_week' => 5, // Friday
                    'deadline_time' => '12:00:00',
                    'is_enabled' => true,
                ],
            );

            // ---------- Shift templates ----------
            $earlyShift = ShiftTemplate::updateOrCreate(
                ['shop_id' => $shop->id, 'name' => '早班'],
                [
                    'start_time' => '10:00:00', 'end_time' => '15:00:00',
                    'days_of_week_bitmask' => 0b1111111,
                    'required_score' => 8, 'min_senior_count' => 0,
                    'min_headcount' => 1, 'max_headcount' => 3,
                    'sort_order' => 1,
                ],
            );
            $midShift = ShiftTemplate::updateOrCreate(
                ['shop_id' => $shop->id, 'name' => '中班'],
                [
                    'start_time' => '15:00:00', 'end_time' => '19:00:00',
                    'days_of_week_bitmask' => 0b1111111,
                    'required_score' => 15, 'min_senior_count' => 1,
                    'min_headcount' => 2, 'max_headcount' => 5,
                    'sort_order' => 2, 'notes' => '尖峰時段',
                ],
            );
            $lateShift = ShiftTemplate::updateOrCreate(
                ['shop_id' => $shop->id, 'name' => '晚班'],
                [
                    'start_time' => '19:00:00', 'end_time' => '22:00:00',
                    'days_of_week_bitmask' => 0b1111111,
                    'required_score' => 10, 'min_senior_count' => 1,
                    'min_headcount' => 2, 'max_headcount' => 4,
                    'sort_order' => 3,
                ],
            );

            // ---------- Employees ----------
            $employeesData = [
                ['name' => '阿明', 'level' => 'lead', 'score' => 10, 'type' => 'full', 'user_id' => $owner->id],
                ['name' => '小華', 'level' => 'senior', 'score' => 5, 'type' => 'full'],
                ['name' => '阿傑', 'level' => 'junior', 'score' => 3, 'type' => 'part'],
                ['name' => '小美', 'level' => 'trainee', 'score' => 2, 'type' => 'part'],
                ['name' => '阿宏', 'level' => 'trainee', 'score' => 2, 'type' => 'part'],
                ['name' => '小雯', 'level' => 'junior', 'score' => 4, 'type' => 'part'],
                ['name' => '阿凱', 'level' => 'trainee', 'score' => 1, 'type' => 'intern'],
                ['name' => '小蓁', 'level' => 'junior', 'score' => 3, 'type' => 'part'],
            ];

            $employees = collect();
            foreach ($employeesData as $i => $data) {
                $employees->push(Employee::updateOrCreate(
                    ['shop_id' => $shop->id, 'name' => $data['name']],
                    [
                        'user_id' => $data['user_id'] ?? null,
                        'binding_level' => isset($data['user_id']) ? 'L2' : 'L0',
                        'phone' => '09' . str_pad((string) (10000000 + $i * 111), 8, '0', STR_PAD_LEFT),
                        'skill_score' => $data['score'],
                        'level' => $data['level'],
                        'employment_type' => $data['type'],
                        'hire_date' => CarbonImmutable::now()->subMonths(rand(1, 24))->toDateString(),
                        'status' => 'active',
                    ],
                ));
            }

            // ---------- This week's schedule (today is in this week) ----------
            $weekStart = CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY);
            $schedule = Schedule::updateOrCreate(
                ['shop_id' => $shop->id, 'week_start_date' => $weekStart->toDateString()],
                [
                    'status' => 'published',
                    'created_by_user_id' => $owner->id,
                    'published_at' => now(),
                    'published_by_user_id' => $owner->id,
                ],
            );

            // 清除舊 entries 再建（避免重複跑時 unique 衝突）
            ScheduleEntry::where('schedule_id', $schedule->id)->delete();

            // Today's shifts
            $today = CarbonImmutable::today();
            ScheduleEntry::create([
                'schedule_id' => $schedule->id,
                'employee_id' => $employees[0]->id,   // 阿明 lead
                'shift_template_id' => $earlyShift->id,
                'date' => $today->toDateString(),
                'status' => 'scheduled',
            ]);

            // Mid shift - intentionally under-staffed to show warning
            foreach ([1, 2, 3, 4] as $idx) {   // 小華, 阿傑, 小美, 阿宏 → 5+3+2+2 = 12, no senior+
                ScheduleEntry::create([
                    'schedule_id' => $schedule->id,
                    'employee_id' => $employees[$idx]->id,
                    'shift_template_id' => $midShift->id,
                    'date' => $today->toDateString(),
                    'status' => 'scheduled',
                ]);
            }

            // Late shift - 阿明 + 阿傑 = 10+3 = 13 ≥ 10
            foreach ([0, 2] as $idx) {
                ScheduleEntry::create([
                    'schedule_id' => $schedule->id,
                    'employee_id' => $employees[$idx]->id,
                    'shift_template_id' => $lateShift->id,
                    'date' => $today->toDateString(),
                    'status' => 'scheduled',
                ]);
            }

            // ---------- Pending leave requests ----------
            LeaveRequest::query()->where('status', 'pending')->delete();
            LeaveRequest::create([
                'employee_id' => $employees[1]->id, // 小華
                'start_datetime' => $today->addDays(2)->setTime(0, 0)->toDateTimeString(),
                'end_datetime' => $today->addDays(2)->setTime(23, 59)->toDateTimeString(),
                'type' => 'personal',
                'reason' => '家中有事',
                'status' => 'pending',
                'source' => 'employee',
                'submitted_at' => now(),
            ]);
            LeaveRequest::create([
                'employee_id' => $employees[2]->id, // 阿傑
                'start_datetime' => $today->addDays(3)->setTime(15, 0)->toDateTimeString(),
                'end_datetime' => $today->addDays(3)->setTime(19, 0)->toDateTimeString(),
                'type' => 'sick',
                'reason' => '感冒發燒',
                'status' => 'pending',
                'source' => 'employee',
                'submitted_at' => now()->subDay(),
            ]);
            LeaveRequest::create([
                'employee_id' => $employees[3]->id, // 小美
                'start_datetime' => $today->addDays(5)->setTime(19, 0)->toDateTimeString(),
                'end_datetime' => $today->addDays(5)->setTime(22, 0)->toDateTimeString(),
                'type' => 'personal',
                'reason' => '家庭聚會',
                'status' => 'pending',
                'source' => 'employee',
                'submitted_at' => now()->subDays(2),
            ]);
        });
    }
}
