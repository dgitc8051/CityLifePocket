<?php

namespace Tests\Unit;

use App\Models\ShiftTemplate;
use PHPUnit\Framework\TestCase;

class ShiftTemplateBitmaskTest extends TestCase
{
    public function test_bitmask_all_days(): void
    {
        $tpl = new ShiftTemplate(['days_of_week_bitmask' => 0b1111111]);
        for ($i = 0; $i < 7; $i++) {
            $this->assertTrue($tpl->appliesToDayOfWeek($i), "Day {$i} should be active");
        }
    }

    public function test_bitmask_weekdays_only(): void
    {
        // Mon-Fri = bit 1,2,3,4,5 = 0b0111110 = 62
        $tpl = new ShiftTemplate(['days_of_week_bitmask' => 0b0111110]);
        $this->assertFalse($tpl->appliesToDayOfWeek(0), 'Sun');
        $this->assertTrue($tpl->appliesToDayOfWeek(1), 'Mon');
        $this->assertTrue($tpl->appliesToDayOfWeek(5), 'Fri');
        $this->assertFalse($tpl->appliesToDayOfWeek(6), 'Sat');
    }

    public function test_bitmask_weekend_only(): void
    {
        // Sun + Sat = bit 0 + bit 6 = 1 + 64 = 65
        $tpl = new ShiftTemplate(['days_of_week_bitmask' => 0b1000001]);
        $this->assertTrue($tpl->appliesToDayOfWeek(0), 'Sun');
        $this->assertFalse($tpl->appliesToDayOfWeek(1), 'Mon');
        $this->assertFalse($tpl->appliesToDayOfWeek(5), 'Fri');
        $this->assertTrue($tpl->appliesToDayOfWeek(6), 'Sat');
    }
}
