<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OncallSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'user_id',
        'start_at',
        'end_at',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeCurrentlyOnCall($query)
    {
        return $query->where('start_at', '<=', now())
                     ->where('end_at', '>=', now());
    }
}
