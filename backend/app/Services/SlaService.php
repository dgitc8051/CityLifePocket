<?php

namespace App\Services;

use App\Models\Incident;
use Carbon\Carbon;

class SlaService
{
    /**
     * Response and resolve time limits in minutes per severity.
     */
    private array $slaRules = [
        'P0' => ['respond' => 5, 'resolve' => 60],
        'P1' => ['respond' => 15, 'resolve' => 240],
        'P2' => ['respond' => 60, 'resolve' => 1440],
        'P3' => ['respond' => 240, 'resolve' => 4320],
    ];

    /**
     * Set SLA deadlines on an incident based on its severity.
     */
    public function setSlaDeadlines(Incident $incident): void
    {
        $rules = $this->slaRules[$incident->severity] ?? $this->slaRules['P3'];

        $incident->update([
            'sla_respond_by' => Carbon::parse($incident->created_at)->addMinutes($rules['respond']),
            'sla_resolve_by' => Carbon::parse($incident->created_at)->addMinutes($rules['resolve']),
        ]);
    }

    /**
     * Check if response SLA is breached.
     */
    public function isResponseBreached(Incident $incident): bool
    {
        if ($incident->responded_at || !$incident->sla_respond_by) {
            return false;
        }

        return now()->gt($incident->sla_respond_by);
    }

    /**
     * Check if resolve SLA is breached.
     */
    public function isResolveBreached(Incident $incident): bool
    {
        if ($incident->resolved_at || !$incident->sla_resolve_by) {
            return false;
        }

        return now()->gt($incident->sla_resolve_by);
    }
}
