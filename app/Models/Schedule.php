<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Schedule extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'week_start_date', 'status',
        'created_by_user_id', 'published_at',
        'published_by_user_id', 'version',
    ];

    protected $casts = [
        'week_start_date' => 'date',
        'published_at' => 'datetime',
        'version' => 'integer',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ScheduleEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
