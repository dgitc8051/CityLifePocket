<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPeriod extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'period_start', 'period_end', 'label',
        'status', 'locked_at', 'paid_at', 'summary_json',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'locked_at' => 'datetime',
        'paid_at' => 'datetime',
        'summary_json' => 'array',
    ];

    public function isLocked(): bool
    {
        return in_array($this->status, ['locked', 'paid'], true);
    }
}
