<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ShiftTemplate;
use App\Models\Shop;
use App\Models\Station;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /** @var array<int, string> */
    private array $employeeNames = [];
    /** @var array<int, string> */
    private array $shiftNames = [];
    /** @var array<int, string> */
    private array $stationNames = [];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isManager()) {
            return response()->json(['error' => '需要店長以上權限'], 403);
        }

        $shop = Auth::user()?->resolveCurrentShop();

        $query = AuditLog::query()
            ->with('user:id,name,role')
            ->where(function ($q) use ($shop) {
                $q->where('shop_id', $shop?->id)->orWhereNull('shop_id');
            })
            ->orderByDesc('created_at');

        if ($action = $request->query('action')) {
            $query->where('action', 'like', "{$action}%");
        }
        if ($entity = $request->query('entity_type')) {
            $query->where('entity_type', $entity);
        }
        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to);
        }

        $limit = min((int) $request->query('limit', 100), 500);
        $logs = $query->limit($limit)->get();

        // 預先載入相關實體名稱避免 N+1
        $this->preloadNames($logs);

        return response()->json([
            'data' => $logs->map(fn ($l) => $this->humanize($l)),
            'meta' => ['count' => $logs->count(), 'limit' => $limit],
        ]);
    }

    private function preloadNames($logs): void
    {
        $employeeIds = collect();
        $shiftIds = collect();
        $stationIds = collect();

        foreach ($logs as $l) {
            if ($l->entity_type === 'Employee') $employeeIds->push($l->entity_id);
            if ($l->entity_type === 'ShiftTemplate') $shiftIds->push($l->entity_id);
            if ($l->entity_type === 'Station') $stationIds->push($l->entity_id);

            // 從 payload 抽相關 id
            foreach ([$l->before_json, $l->after_json] as $payload) {
                if (! $payload) continue;
                if (isset($payload['employee_id'])) $employeeIds->push($payload['employee_id']);
                if (isset($payload['shift_template_id'])) $shiftIds->push($payload['shift_template_id']);
                if (isset($payload['from_employee_id'])) $employeeIds->push($payload['from_employee_id']);
                if (isset($payload['to_employee_id'])) $employeeIds->push($payload['to_employee_id']);
                if (isset($payload['from_schedule_entry_id'])) {
                    // entries 沒法靠 id 撈名字，但會在 summary 用其他線索
                }
            }
        }

        if ($employeeIds->isNotEmpty()) {
            $this->employeeNames = Employee::whereIn('id', $employeeIds->unique())
                ->pluck('name', 'id')->toArray();
        }
        if ($shiftIds->isNotEmpty()) {
            $this->shiftNames = ShiftTemplate::whereIn('id', $shiftIds->unique())
                ->pluck('name', 'id')->toArray();
        }
        if ($stationIds->isNotEmpty()) {
            $this->stationNames = Station::whereIn('id', $stationIds->unique())
                ->pluck('name', 'id')->toArray();
        }
    }

    private function humanize(AuditLog $l): array
    {
        $summary = $this->buildSummary($l);
        $diff = $this->buildDiff($l);

        return [
            'id' => $l->id,
            'created_at' => $l->created_at?->toIso8601String(),
            'time_label' => $l->created_at?->locale('zh_TW')->isoFormat('M/D HH:mm'),
            'user_name' => $l->user?->name ?? '系統',
            'user_role' => $l->user?->role,
            'action' => $l->action,
            'action_label' => $this->actionLabel($l->action),
            'tone' => $this->actionTone($l->action),
            'summary' => $summary,
            'diff' => $diff,
            'ip_address' => $l->ip_address,
        ];
    }

    private function actionLabel(string $action): string
    {
        return [
            'employee.create' => '新增員工',
            'employee.update' => '更新員工',
            'employee.terminate' => '員工離職',
            'shift_template.create' => '新增時段',
            'shift_template.update' => '更新時段',
            'shift_template.deactivate' => '停用時段',
            'holiday.create' => '新增公休',
            'holiday.update' => '更新公休',
            'holiday.delete' => '刪除公休',
            'business_hours.bulk_update' => '更新營業時間',
            'shop.update' => '更新店家資料',
            'shop.update_line' => '更新 LINE 設定',
            'user.bind_phone' => '員工綁定手機號碼',
            'schedule_entry.create' => '排班加入',
            'schedule_entry.delete' => '排班移除',
            'schedule.publish' => '發布班表',
            'schedule.copy' => '複製班表',
            'schedule.clear' => '一鍵刪除班表',
            'schedule.auto_generate' => 'AI 一鍵排班',
            'availability.update' => '更新可上時段',
            'station.create' => '新增站別',
            'station.update' => '更新站別',
            'station.delete' => '刪除站別',
            'shift_swap.create' => '送出換班申請',
            'shift_swap.approve' => '核准換班',
            'shift_swap.reject' => '拒絕換班',
            'shift_swap.cancel' => '取消換班',
            'attendance.clock_in' => '打卡上班',
            'attendance.clock_out' => '打卡下班',
            'attendance.delete' => '刪除打卡紀錄',
            'create' => '建立',
            'update' => '更新',
            'approve' => '核准請假',
            'reject' => '拒絕請假',
            'cancel' => '取消',
        ][$action] ?? $action;
    }

    private function actionTone(string $action): string
    {
        if (str_contains($action, 'delete') || str_contains($action, 'terminate') || str_contains($action, 'deactivate') || str_contains($action, 'reject')) {
            return 'danger';
        }
        if (str_contains($action, 'create') || str_contains($action, 'approve') || str_contains($action, 'publish')) {
            return 'success';
        }
        return 'neutral';
    }

    private function buildSummary(AuditLog $l): string
    {
        $b = $l->before_json ?? [];
        $a = $l->after_json ?? [];

        return match (true) {
            $l->action === 'employee.create' => "新增員工：{$a['name']}（{$this->levelLabel($a['level'] ?? '')}・分數 ".($a['skill_score'] ?? '?').'）',
            $l->action === 'employee.update' => "更新員工：{$a['name']}",
            $l->action === 'employee.terminate' => "員工離職：{$b['name']}",

            $l->action === 'shift_template.create' => "新增時段「{$a['name']}」"
                . ' '. substr($a['start_time'] ?? '', 0, 5) .'–'.substr($a['end_time'] ?? '', 0, 5),
            $l->action === 'shift_template.update' => "更新時段「{$a['name']}」",
            $l->action === 'shift_template.deactivate' => "停用時段「{$b['name']}」",

            $l->action === 'holiday.create' => "新增公休 {$a['date']}".(isset($a['note']) ? "（{$a['note']}）" : ''),
            $l->action === 'holiday.update' => "更新公休 {$a['date']}",
            $l->action === 'holiday.delete' => "刪除公休 {$b['date']}",

            $l->action === 'business_hours.bulk_update' => '更新每週營業時間',
            $l->action === 'shop.update' => '更新店家基本資料',

            $l->action === 'schedule_entry.create' => sprintf(
                '把【%s】排入【%s %s】',
                $this->empName($a['employee_id'] ?? null),
                $this->dateLabel($a['date'] ?? null),
                $this->shiftName($a['shift_template_id'] ?? null),
            ),
            $l->action === 'schedule_entry.delete' => sprintf(
                '從【%s %s】移除【%s】',
                $this->dateLabel($b['date'] ?? null),
                $this->shiftName($b['shift_template_id'] ?? null),
                $this->empName($b['employee_id'] ?? null),
            ),

            $l->action === 'schedule.publish' => '發布班表',
            $l->action === 'schedule.copy' => sprintf(
                '複製 %s → %s （%d 項，跳過 %d）',
                $this->dateLabel($b['source_week'] ?? null),
                $this->dateLabel($a['target_week'] ?? null),
                $a['copied'] ?? 0,
                $a['skipped'] ?? 0,
            ),

            $l->action === 'schedule.auto_generate' => sprintf(
                'AI 一鍵排班 %s（策略：%s，新增 %d 項）',
                $this->dateLabel($b['week'] ?? null),
                $this->strategyLabel($a['strategy'] ?? 'balanced'),
                $a['created'] ?? 0,
            ),

            $l->action === 'schedule.clear' => sprintf(
                '一鍵刪除 %s 起 %d 天的班表（刪除 %d 項）',
                $this->dateLabel($b['week'] ?? null),
                $b['days'] ?? 0,
                $a['deleted'] ?? 0,
            ),

            $l->action === 'station.create' => sprintf('新增站別「%s」', $a['name'] ?? '?'),
            $l->action === 'station.update' => sprintf('更新站別「%s」', $a['name'] ?? $b['name'] ?? '?'),
            $l->action === 'station.delete' => sprintf('刪除站別「%s」', $b['name'] ?? '?'),

            in_array($l->action, ['shift_swap.create', 'shift_swap.approve', 'shift_swap.reject', 'shift_swap.cancel'], true)
                => $this->swapSummary($l),

            $l->action === 'availability.update' => sprintf(
                '%s【%s】%s 那週的可上時段（%d 項變更）',
                ($a['source'] ?? '') === 'manager_proxy' ? '代填' : '提交',
                $this->empName($l->entity_id),
                $this->dateLabel($b['week_start'] ?? null),
                isset($a['changes']) ? count($a['changes']) : 0,
            ),

            str_starts_with($l->action, 'leave') || in_array($l->action, ['approve', 'reject', 'cancel', 'create', 'update'], true) => $this->leaveSummary($l),

            default => "{$this->actionLabel($l->action)}",
        };
    }

    private function swapSummary(AuditLog $l): string
    {
        $p = $l->after_json ?? $l->before_json ?? [];
        $fromName = $this->empName($p['from_employee_id'] ?? null);
        $toName = $this->empName($p['to_employee_id'] ?? null);
        $oneWay = empty($p['to_schedule_entry_id']) ? '單向代班' : '雙向交換';

        $verb = match ($l->action) {
            'shift_swap.create' => '送出換班申請',
            'shift_swap.approve' => '核准換班',
            'shift_swap.reject' => '拒絕換班',
            'shift_swap.cancel' => '取消換班',
            default => '換班',
        };

        return "{$verb}：{$fromName} → {$toName}（{$oneWay}）";
    }

    private function leaveSummary(AuditLog $l): string
    {
        $b = $l->before_json ?? [];
        $a = $l->after_json ?? [];

        // 從 entity_type 確認是 LeaveRequest
        if ($l->entity_type !== 'LeaveRequest') {
            return $this->actionLabel($l->action);
        }

        $empId = $a['employee_id'] ?? $b['employee_id'] ?? null;
        $empName = $this->empName($empId);
        $type = $this->leaveTypeLabel($a['type'] ?? $b['type'] ?? '');

        $action = match ($l->action) {
            'create' => '提交請假',
            'update' => '修改請假',
            'approve' => '核准請假',
            'reject' => '拒絕請假',
            'cancel' => '取消請假',
            default => $l->action,
        };

        return "{$action}：{$empName}・{$type}";
    }

    /**
     * 變更摘要：欄位 → 中文 + 變更前/後
     */
    private function buildDiff(AuditLog $l): array
    {
        $b = $l->before_json ?? [];
        $a = $l->after_json ?? [];

        // 排班類型不顯示欄位 diff（資訊已在 summary）
        if (in_array($l->action, ['schedule_entry.create', 'schedule_entry.delete', 'schedule.copy', 'schedule.publish'], true)) {
            return [];
        }

        // 站別需求變更：以站別為單位列出新增/移除/變更
        if ($l->action === 'shift_template.update' && (isset($b['station_requirements']) || isset($a['station_requirements']))) {
            $beforeMap = collect($b['station_requirements'] ?? [])->keyBy('station_id');
            $afterMap = collect($a['station_requirements'] ?? [])->keyBy('station_id');
            $allIds = $beforeMap->keys()->concat($afterMap->keys())->unique();
            $stationDiff = [];
            foreach ($allIds as $sid) {
                $bRow = $beforeMap->get($sid);
                $aRow = $afterMap->get($sid);
                $name = $aRow['name'] ?? $bRow['name'] ?? "站別 #{$sid}";
                if (! $bRow && $aRow) {
                    $stationDiff[] = ['field' => "站別「{$name}」", 'before' => '—', 'after' => "≥ {$aRow['min_count']} 人"];
                } elseif ($bRow && ! $aRow) {
                    $stationDiff[] = ['field' => "站別「{$name}」", 'before' => "≥ {$bRow['min_count']} 人", 'after' => '— 已移除'];
                } elseif ($bRow && $aRow && $bRow['min_count'] !== $aRow['min_count']) {
                    $stationDiff[] = ['field' => "站別「{$name}」", 'before' => "≥ {$bRow['min_count']} 人", 'after' => "≥ {$aRow['min_count']} 人"];
                }
            }
            // 仍然繼續算其他欄位的 diff，合併在最後
            // (不 return — 讓下面的 fields 邏輯繼續跑)
        } else {
            $stationDiff = [];
        }

        // availability 直接條列每個變更
        if ($l->action === 'availability.update' && isset($a['changes'])) {
            return collect($a['changes'])->map(function ($c) {
                $dayLabels = ['週日', '週一', '週二', '週三', '週四', '週五', '週六'];
                $day = $dayLabels[$c['day_of_week']] ?? '';
                $shift = $this->shiftName($c['shift_template_id'] ?? null);
                $beforeA = $this->availLabel($c['before']['availability'] ?? null);
                $afterA = $this->availLabel($c['after']['availability'] ?? null);
                return [
                    'field' => "{$day} {$shift}",
                    'before' => $beforeA,
                    'after' => $afterA,
                ];
            })->all();
        }

        $fields = [
            'name' => '名稱',
            'skill_score' => '能力分數',
            'level' => '等級',
            'employment_type' => '雇用類型',
            'status' => '狀態',
            'phone' => '電話',
            'hire_date' => '入職',
            'weekly_max_hours' => '週上限',
            'hourly_wage' => '時薪',
            'notes' => '備註',
            'start_time' => '開始',
            'end_time' => '結束',
            'days_of_week_bitmask' => '適用日',
            'required_score' => '建議總分',
            'min_senior_count' => '高階下限',
            'min_headcount' => '人數下限',
            'max_headcount' => '人數上限',
            'is_active' => '啟用',
            'date' => '日期',
            'type' => '類型',
            'note' => '備註',
            'reason' => '原因',
            'review_note' => '審核備註',
            'open_time' => '開店',
            'close_time' => '關店',
            'is_closed' => '公休',
            'timezone' => '時區',
            'line_channel_id' => 'LINE 頻道',
            'color' => '顏色',
            'sort_order' => '排序',
        ];

        $diff = [];
        foreach ($fields as $key => $label) {
            $before = $b[$key] ?? null;
            $after = $a[$key] ?? null;
            if ($before === $after) continue;
            if ($before === null && $after === null) continue;

            $diff[] = [
                'field' => $label,
                'before' => $this->valueLabel($key, $before),
                'after' => $this->valueLabel($key, $after),
            ];
        }

        // 合併站別需求變更
        return array_merge($diff, $stationDiff ?? []);
    }

    // ---- Helpers ----

    private function empName(?int $id): string
    {
        if (! $id) return '?';
        return $this->employeeNames[$id] ?? "員工 #{$id}";
    }

    private function shiftName(?int $id): string
    {
        if (! $id) return '時段';
        return $this->shiftNames[$id] ?? "時段 #{$id}";
    }

    private function dateLabel(?string $date): string
    {
        if (! $date) return '';
        try {
            return Carbon::parse($date)->locale('zh_TW')->isoFormat('M/D ddd');
        } catch (\Throwable $e) {
            return $date;
        }
    }

    private function strategyLabel(?string $s): string
    {
        return ['balanced' => '平衡', 'cheap' => '省錢', 'senior' => '重資深'][$s] ?? ($s ?? '');
    }

    private function levelLabel(?string $level): string
    {
        return ['lead' => '領班', 'senior' => '熟手', 'junior' => '初階', 'trainee' => '新手'][$level] ?? $level ?? '';
    }

    private function leaveTypeLabel(?string $type): string
    {
        return ['personal' => '事假', 'sick' => '病假', 'annual' => '特休', 'funeral' => '喪假', 'marriage' => '婚假', 'other' => '其他'][$type] ?? $type ?? '';
    }

    private function availLabel(?string $a): string
    {
        return ['available' => '✓ 可上', 'unavailable' => '✗ 不行', 'maybe' => '? 不確定'][$a] ?? '— 未填';
    }

    private function valueLabel(string $key, $value): string
    {
        if ($value === null || $value === '') return '—';
        if (is_bool($value)) return $value ? '是' : '否';

        return match ($key) {
            'level' => $this->levelLabel($value),
            'employment_type' => ['full' => '全職', 'part' => '兼職', 'intern' => '實習'][$value] ?? $value,
            'status' => ['active' => '在職', 'leave' => '長假', 'terminated' => '離職',
                'scheduled' => '排定', 'confirmed' => '確認', 'cancelled' => '取消',
                'draft' => '草稿', 'published' => '已發布', 'archived' => '已封存',
                'pending' => '待審', 'approved' => '已核准', 'rejected' => '已拒絕'][$value] ?? $value,
            'type' => $this->leaveTypeLabel($value),
            'days_of_week_bitmask' => $this->bitmaskLabel((int) $value),
            'start_time', 'end_time', 'open_time', 'close_time' => substr((string) $value, 0, 5),
            default => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value,
        };
    }

    private function bitmaskLabel(int $bitmask): string
    {
        $labels = ['日', '一', '二', '三', '四', '五', '六'];
        $active = [];
        for ($i = 0; $i < 7; $i++) {
            if ($bitmask & (1 << $i)) $active[] = $labels[$i];
        }
        if (count($active) === 7) return '每天';
        return implode('、', $active);
    }
}
