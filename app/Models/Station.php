<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Station extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'name', 'color', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_stations')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function shiftTemplates(): BelongsToMany
    {
        return $this->belongsToMany(ShiftTemplate::class, 'shift_template_stations')
            ->withPivot('min_count')
            ->withTimestamps();
    }
}
