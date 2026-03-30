<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
    ];

    public function members()
    {
        return $this->belongsToMany(User::class);
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function oncallSchedules()
    {
        return $this->hasMany(OncallSchedule::class);
    }

    public function routingRules()
    {
        return $this->hasMany(RoutingRule::class);
    }
}
