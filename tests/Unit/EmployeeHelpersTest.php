<?php

namespace Tests\Unit;

use App\Models\Employee;
use PHPUnit\Framework\TestCase;

class EmployeeHelpersTest extends TestCase
{
    public function test_lead_is_senior(): void
    {
        $emp = new Employee(['level' => 'lead']);
        $this->assertTrue($emp->isSenior());
    }

    public function test_senior_is_senior(): void
    {
        $emp = new Employee(['level' => 'senior']);
        $this->assertTrue($emp->isSenior());
    }

    public function test_junior_is_not_senior(): void
    {
        $emp = new Employee(['level' => 'junior']);
        $this->assertFalse($emp->isSenior());
    }

    public function test_trainee_is_not_senior(): void
    {
        $emp = new Employee(['level' => 'trainee']);
        $this->assertFalse($emp->isSenior());
    }
}
