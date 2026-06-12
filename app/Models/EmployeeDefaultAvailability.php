<?php

namespace App\Models;

use App\Support\Tenancy\IndirectBelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDefaultAvailability extends Model
{
    use IndirectBelongsToShop;

    protected string $shopVia = 'employee';

    protected $fillable = [
        'employee_id', 'day_of_week',
        'shift_template_id', 'availability',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class);
    }
}
