<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopSalaryMultiplier extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'label', 'multiplier',
        'condition_type', 'condition_json',
        'sort_order', 'is_active',
    ];

    protected $casts = [
        'multiplier' => 'decimal:2',
        'condition_json' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
