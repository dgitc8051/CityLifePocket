<?php

namespace App\Models;

use App\Support\Tenancy\IndirectBelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnualLeaveAccrual extends Model
{
    use IndirectBelongsToShop;

    protected string $shopVia = 'employee';

    protected $fillable = [
        'employee_id', 'cycle_start', 'cycle_end',
        'quota_days', 'used_days', 'expired_days', 'payout_days', 'basis_json',
    ];

    protected $casts = [
        'cycle_start' => 'date',
        'cycle_end' => 'date',
        'quota_days' => 'integer',
        'used_days' => 'decimal:2',
        'expired_days' => 'decimal:2',
        'payout_days' => 'decimal:2',
        'basis_json' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function remainingDays(): float
    {
        return max(0, (float) $this->quota_days - (float) $this->used_days - (float) $this->expired_days - (float) $this->payout_days);
    }
}
