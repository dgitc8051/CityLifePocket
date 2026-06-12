<?php

namespace App\Models;

use App\Support\Tenancy\IndirectBelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleEntry extends Model
{
    use IndirectBelongsToShop;

    /** ScheduleEntry 透過 schedule.shop_id 隔離 */
    protected string $shopVia = 'schedule';

    protected $fillable = [
        'schedule_id', 'employee_id', 'shift_template_id',
        'date', 'custom_start_time', 'custom_end_time',
        'status', 'notes',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class);
    }
}
