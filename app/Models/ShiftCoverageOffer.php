<?php

namespace App\Models;

use App\Support\Tenancy\IndirectBelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftCoverageOffer extends Model
{
    use IndirectBelongsToShop;

    protected string $shopVia = 'volunteer';

    protected $fillable = [
        'coverage_request_id', 'volunteer_employee_id', 'message',
        'status', 'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function coverageRequest(): BelongsTo
    {
        return $this->belongsTo(ShiftCoverageRequest::class);
    }

    public function volunteer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'volunteer_employee_id');
    }
}
