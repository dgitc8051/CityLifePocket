<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_number',
        'title',
        'description',
        'type',
        'category',
        'severity',
        'status',
        'asset_id',
        'reporter_name',
        'reporter_contact',
        'triage_rule_matched',
        'sla_respond_by',
        'sla_resolve_by',
        'responded_at',
        'resolved_at',
        'resolution_note',
        'resolution_cost',
        'escalation_level',
    ];

    protected function casts(): array
    {
        return [
            'sla_respond_by' => 'datetime',
            'sla_resolve_by' => 'datetime',
            'responded_at' => 'datetime',
            'resolved_at' => 'datetime',
            'resolution_cost' => 'decimal:2',
        ];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function assignments()
    {
        return $this->hasMany(IncidentAssignment::class);
    }

    public function currentAssignment()
    {
        return $this->hasOne(IncidentAssignment::class)->latestOfMany();
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }
}
