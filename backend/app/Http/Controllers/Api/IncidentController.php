<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Incident;
use App\Models\IncidentAssignment;
use App\Services\AuditLogService;
use App\Services\RoutingService;
use App\Services\SlaService;
use App\Services\TriageService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function __construct(
        private TriageService $triageService,
        private RoutingService $routingService,
        private SlaService $slaService,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * POST /api/incidents
     * Create a new incident (public, no auth required).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category' => 'required|string',
            'type' => 'in:equipment,software',
            'asset_id' => 'nullable|exists:assets,id',
            'reporter_name' => 'required|string|max:255',
            'reporter_contact' => 'nullable|string|max:255',
        ]);

        // If asset_id provided, derive type and category from asset
        $asset = null;
        if (!empty($validated['asset_id'])) {
            $asset = Asset::find($validated['asset_id']);
            $validated['type'] = $asset->type;
            $validated['category'] = $asset->category;
        }

        // Auto-triage: determine severity
        $triage = $this->triageService->evaluate(
            $validated['description'],
            $validated['category'],
        );

        // Generate incident number
        $today = Carbon::today()->format('Ymd');
        $count = Incident::whereDate('created_at', Carbon::today())->count() + 1;
        $incidentNumber = "INC-{$today}-" . str_pad($count, 3, '0', STR_PAD_LEFT);

        // Create incident
        $incident = Incident::create([
            'incident_number' => $incidentNumber,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'type' => $validated['type'] ?? 'equipment',
            'category' => $validated['category'],
            'severity' => $triage['severity'],
            'status' => 'triaged',
            'asset_id' => $validated['asset_id'] ?? null,
            'reporter_name' => $validated['reporter_name'],
            'reporter_contact' => $validated['reporter_contact'] ?? null,
            'triage_rule_matched' => $triage['rule_matched'],
        ]);

        // Set SLA deadlines
        $this->slaService->setSlaDeadlines($incident);

        // Audit: created
        $this->auditLogService->log('created', $incident->id);
        $this->auditLogService->log('auto_triaged', $incident->id, after: [
            'severity' => $triage['severity'],
            'rule' => $triage['rule_matched'],
        ]);

        // Auto-route: find on-call user
        $assignee = $asset
            ? $this->routingService->findOnCallUserForAsset($asset)
            : $this->routingService->findOnCallUser($validated['category']);

        if ($assignee) {
            IncidentAssignment::create([
                'incident_id' => $incident->id,
                'user_id' => $assignee->id,
                'assigned_at' => now(),
            ]);

            $incident->update(['status' => 'assigned']);

            $this->auditLogService->log('auto_assigned', $incident->id, after: [
                'assignee' => $assignee->name,
                'user_id' => $assignee->id,
            ]);
        }

        return response()->json([
            'data' => $incident->load(['asset', 'currentAssignment.user']),
            'message' => '事件已建立',
        ], 201);
    }

    /**
     * GET /api/incidents
     */
    public function index(Request $request): JsonResponse
    {
        $query = Incident::with(['asset', 'currentAssignment.user']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        $incidents = $query->orderByDesc('created_at')->paginate(20);

        return response()->json($incidents);
    }

    /**
     * GET /api/incidents/{id}
     */
    public function show(Incident $incident): JsonResponse
    {
        $incident->load([
            'asset',
            'assignments.user',
            'currentAssignment.user',
            'auditLogs',
            'notifications.recipient',
        ]);

        return response()->json(['data' => $incident]);
    }

    /**
     * PATCH /api/incidents/{id}
     */
    public function update(Request $request, Incident $incident): JsonResponse
    {
        $validated = $request->validate([
            'severity' => 'sometimes|in:P0,P1,P2,P3',
            'status' => 'sometimes|in:new,triaged,assigned,in_progress,resolved,closed',
            'resolution_note' => 'sometimes|string',
            'resolution_cost' => 'sometimes|numeric|min:0',
        ]);

        $before = $incident->only(array_keys($validated));
        $incident->update($validated);

        $this->auditLogService->log(
            'updated',
            $incident->id,
            $request->user()?->id,
            'user',
            $before,
            $validated,
        );

        return response()->json(['data' => $incident->fresh()]);
    }

    /**
     * POST /api/incidents/{id}/acknowledge
     */
    public function acknowledge(Request $request, Incident $incident): JsonResponse
    {
        $assignment = $incident->currentAssignment;

        if (!$assignment) {
            return response()->json(['message' => '此事件尚未指派'], 400);
        }

        if ($assignment->acked_at) {
            return response()->json(['message' => '已確認接手'], 400);
        }

        $assignment->update(['acked_at' => now()]);
        $incident->update([
            'status' => 'in_progress',
            'responded_at' => now(),
        ]);

        $this->auditLogService->log(
            'acknowledged',
            $incident->id,
            $assignment->user_id,
            'user',
        );

        return response()->json([
            'data' => $incident->fresh()->load('currentAssignment.user'),
            'message' => '已確認接手',
        ]);
    }

    /**
     * POST /api/incidents/{id}/arrive
     */
    public function arrive(Request $request, Incident $incident): JsonResponse
    {
        $assignment = $incident->currentAssignment;

        if (!$assignment) {
            return response()->json(['message' => '此事件尚未指派'], 400);
        }

        $assignment->update(['arrived_at' => now()]);

        $this->auditLogService->log(
            'arrived',
            $incident->id,
            $assignment->user_id,
            'user',
        );

        return response()->json([
            'data' => $incident->fresh()->load('currentAssignment.user'),
            'message' => '已確認到場',
        ]);
    }

    /**
     * POST /api/incidents/{id}/resolve
     */
    public function resolve(Request $request, Incident $incident): JsonResponse
    {
        $validated = $request->validate([
            'resolution_note' => 'required|string',
            'resolution_cost' => 'nullable|numeric|min:0',
        ]);

        $incident->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_note' => $validated['resolution_note'],
            'resolution_cost' => $validated['resolution_cost'] ?? null,
        ]);

        $this->auditLogService->log(
            'resolved',
            $incident->id,
            $request->user()?->id ?? $incident->currentAssignment?->user_id,
            'user',
            after: [
                'resolution_note' => $validated['resolution_note'],
                'resolution_cost' => $validated['resolution_cost'] ?? null,
            ],
        );

        return response()->json([
            'data' => $incident->fresh(),
            'message' => '事件已解決',
        ]);
    }

    /**
     * POST /api/incidents/{id}/escalate
     */
    public function escalate(Request $request, Incident $incident): JsonResponse
    {
        $newLevel = $incident->escalation_level + 1;

        // Find the team from current assignment or routing rule
        $teamId = null;
        if ($incident->asset) {
            $teamId = $incident->asset->team_id;
        } else {
            $rule = \App\Models\RoutingRule::where('category', $incident->category)->first();
            $teamId = $rule?->team_id;
        }

        $escalationTarget = $teamId
            ? $this->routingService->findEscalationTarget($teamId, $newLevel)
            : null;

        $incident->update(['escalation_level' => $newLevel]);

        if ($escalationTarget) {
            IncidentAssignment::create([
                'incident_id' => $incident->id,
                'user_id' => $escalationTarget->id,
                'assigned_at' => now(),
            ]);
        }

        $this->auditLogService->log(
            'escalated',
            $incident->id,
            $request->user()?->id,
            $request->user() ? 'user' : 'system',
            after: [
                'escalation_level' => $newLevel,
                'escalated_to' => $escalationTarget?->name,
            ],
        );

        return response()->json([
            'data' => $incident->fresh()->load('currentAssignment.user'),
            'message' => "已升級至第 {$newLevel} 層",
        ]);
    }
}
