<?php

namespace App\Models;

use App\Support\Tenancy\IndirectBelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftSwapRequest extends Model
{
    use IndirectBelongsToShop;

    /** 透過 fromEmployee 那一邊的 shop_id 隔離(發起方一定在自己 shop) */
    protected string $shopVia = 'fromEmployee';

    protected $fillable = [
        'from_employee_id', 'to_employee_id',
        'from_schedule_entry_id', 'to_schedule_entry_id',
        'status', 'reason', 'requested_at', 'reviewed_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function fromEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    public function toEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    public function fromEntry(): BelongsTo
    {
        return $this->belongsTo(ScheduleEntry::class, 'from_schedule_entry_id');
    }

    public function toEntry(): BelongsTo
    {
        return $this->belongsTo(ScheduleEntry::class, 'to_schedule_entry_id');
    }
}
