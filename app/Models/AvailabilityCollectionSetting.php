<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvailabilityCollectionSetting extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'push_day_of_week', 'push_time',
        'deadline_day_of_week', 'deadline_time', 'is_enabled',
    ];

    protected $casts = [
        'push_day_of_week' => 'integer',
        'deadline_day_of_week' => 'integer',
        'is_enabled' => 'boolean',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
