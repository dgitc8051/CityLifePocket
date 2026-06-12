<?php

namespace App\Models;

use App\Support\Tenancy\IndirectBelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAvailability extends Model
{
    use IndirectBelongsToShop;

    protected string $shopVia = 'employee';

    protected $fillable = [
        'employee_id', 'week_start_date', 'day_of_week',
        'shift_template_id', 'availability', 'note',
        'submitted_at', 'source',
    ];

    protected $casts = [
        'week_start_date' => 'date',
        'day_of_week' => 'integer',
        'submitted_at' => 'datetime',
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
