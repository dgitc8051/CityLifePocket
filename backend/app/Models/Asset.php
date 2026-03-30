<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_number',
        'name',
        'type',
        'category',
        'location',
        'model',
        'qr_code',
        'team_id',
        'installed_at',
        'warranty_expires_at',
        'last_maintained_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'installed_at' => 'date',
            'warranty_expires_at' => 'date',
            'last_maintained_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function incidents()
    {
        return $this->hasMany(Incident::class);
    }

    public function maintenanceLogs()
    {
        return $this->hasMany(AssetMaintenanceLog::class);
    }
}
