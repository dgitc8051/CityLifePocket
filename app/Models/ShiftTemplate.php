<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShiftTemplate extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = [
        'shop_id', 'name', 'start_time', 'end_time',
        'days_of_week_bitmask', 'required_score',
        'min_senior_count', 'min_headcount', 'max_headcount',
        'is_active', 'sort_order', 'notes',
    ];

    protected $casts = [
        'days_of_week_bitmask' => 'integer',
        'required_score' => 'integer',
        'min_senior_count' => 'integer',
        'min_headcount' => 'integer',
        'max_headcount' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function scheduleEntries(): HasMany
    {
        return $this->hasMany(ScheduleEntry::class);
    }

    public function appliesToDayOfWeek(int $dow): bool
    {
        return ($this->days_of_week_bitmask & (1 << $dow)) !== 0;
    }

    public function requiredStations(): BelongsToMany
    {
        return $this->belongsToMany(Station::class, 'shift_template_stations')
            ->withPivot('min_count')
            ->withTimestamps();
    }
}
