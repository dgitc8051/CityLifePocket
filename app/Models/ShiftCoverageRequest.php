<?php

namespace App\Models;

use App\Support\Tenancy\IndirectBelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftCoverageRequest extends Model
{
    use IndirectBelongsToShop;

    protected string $shopVia = 'requester';

    protected $fillable = [
        'schedule_entry_id', 'requester_employee_id', 'reason',
        'status', 'expires_at',
        'accepted_offer_id', 'accepted_employee_id', 'fulfilled_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'fulfilled_at' => 'datetime',
    ];

    public function scheduleEntry(): BelongsTo
    {
        return $this->belongsTo(ScheduleEntry::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requester_employee_id');
    }

    public function acceptedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'accepted_employee_id');
    }

    public function acceptedOffer(): BelongsTo
    {
        return $this->belongsTo(ShiftCoverageOffer::class, 'accepted_offer_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(ShiftCoverageOffer::class, 'coverage_request_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
