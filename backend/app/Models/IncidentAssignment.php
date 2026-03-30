<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IncidentAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_id',
        'user_id',
        'assigned_at',
        'acked_at',
        'arrived_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'acked_at' => 'datetime',
            'arrived_at' => 'datetime',
        ];
    }

    public function incident()
    {
        return $this->belongsTo(Incident::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
