<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoutingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'team_id',
        'priority_weight',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
