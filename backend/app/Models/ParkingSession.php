<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParkingSession extends Model
{
    protected $fillable = [
        'session_token',
        'user_id',
        'lat',
        'lng',
        'accuracy',
        'floor',
        'zone',
        'spot_number',
        'photos_json',
        'custom_note',
        'is_underground',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'accuracy' => 'decimal:2',
            'photos_json' => 'array',
            'is_underground' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->whereNull('completed_at');
    }

    public function scopeCompleted($query)
    {
        return $query->whereNotNull('completed_at');
    }
}
