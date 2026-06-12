<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    use BelongsToShop;

    protected $fillable = ['shop_id', 'date', 'type', 'note', 'is_recurring', 'recurrence_rule'];

    protected $casts = [
        'date' => 'date',
        'is_recurring' => 'boolean',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
