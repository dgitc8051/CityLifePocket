<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\OncallSchedule;
use App\Models\RoutingRule;
use App\Models\User;

class RoutingService
{
    /**
     * Find the on-call user for a given category.
     * Flow: category -> routing rule -> team -> on-call schedule -> user
     *
     * @return User|null
     */
    public function findOnCallUser(string $category): ?User
    {
        // Find the responsible team via routing rules
        $rule = RoutingRule::where('category', $category)->first();

        if (!$rule) {
            return null;
        }

        // Find who is currently on-call for that team
        $schedule = OncallSchedule::where('team_id', $rule->team_id)
            ->currentlyOnCall()
            ->first();

        if (!$schedule) {
            return null;
        }

        return $schedule->user;
    }

    /**
     * Find the on-call user for a given asset.
     */
    public function findOnCallUserForAsset(Asset $asset): ?User
    {
        // First try via asset's team directly
        $schedule = OncallSchedule::where('team_id', $asset->team_id)
            ->currentlyOnCall()
            ->first();

        if ($schedule) {
            return $schedule->user;
        }

        // Fallback to routing rule by category
        return $this->findOnCallUser($asset->category);
    }

    /**
     * Find the escalation target (lead or manager) for a team.
     */
    public function findEscalationTarget(int $teamId, int $escalationLevel): ?User
    {
        $targetRole = match ($escalationLevel) {
            1 => 'lead',
            2 => 'manager',
            default => 'admin',
        };

        // Find a user with the target role in the team
        $user = User::whereHas('teams', fn ($q) => $q->where('teams.id', $teamId))
            ->where('role', $targetRole)
            ->where('is_active', true)
            ->first();

        // If no lead/manager in team, find any with that role
        if (!$user) {
            $user = User::where('role', $targetRole)
                ->where('is_active', true)
                ->first();
        }

        return $user;
    }
}
