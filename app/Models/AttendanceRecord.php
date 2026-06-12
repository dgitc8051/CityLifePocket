<?php

namespace App\Models;

use App\Support\Tenancy\IndirectBelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    use IndirectBelongsToShop;

    protected string $shopVia = 'employee';

    protected $fillable = [
        'employee_id', 'schedule_entry_id',
        'clocked_in_at', 'clocked_out_at',
        'clock_in_lat', 'clock_in_lng',
        'clock_out_lat', 'clock_out_lng',
        'location_verified',
        'late_minutes',
        'overtime_minutes_detected',
        'overtime_minutes_approved',
        'overtime_approved_by', 'overtime_approved_at',
        'status', 'note',
    ];

    protected $casts = [
        'clocked_in_at' => 'datetime',
        'clocked_out_at' => 'datetime',
        'overtime_approved_at' => 'datetime',
        'clock_in_lat' => 'decimal:7',
        'clock_in_lng' => 'decimal:7',
        'clock_out_lat' => 'decimal:7',
        'clock_out_lng' => 'decimal:7',
        'location_verified' => 'boolean',
        'late_minutes' => 'integer',
        'overtime_minutes_detected' => 'integer',
        'overtime_minutes_approved' => 'integer',
    ];

    public function overtimeApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overtime_approved_by');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scheduleEntry(): BelongsTo
    {
        return $this->belongsTo(ScheduleEntry::class);
    }
}
