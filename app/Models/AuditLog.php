<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use BelongsToShop;

    public $timestamps = false;
    protected $fillable = [
        'user_id', 'shop_id', 'action', 'entity_type', 'entity_id',
        'before_json', 'after_json', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
