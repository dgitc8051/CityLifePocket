<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Schedule;
use App\Models\ScheduleEntry;
use App\Models\ShiftTemplate;
use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function weeklyHours(Request $request): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $weekStart = $this->resolveWeekStart($request->query('week'));
        $weekEnd = $weekStart->addDays(6);

        $schedule = Schedule::where('shop_id', $shop->id)
            ->where('week_start_date', $weekStart->toDateString())
            ->first();

        if (! $schedule) {
            return response()->json([
                'week_start' => $weekStart->toDateString(),
                'rows' => [],
                'totals' => ['employees' => 0, 'shifts' => 0, 'hours' => 0],
            ]);
        }

        $entries = ScheduleEntry::where('schedule_id', $schedule->id)
            ->with('shiftTemplate:id,start_time,end_time')
            ->get();

        $employees = Employee::where('shop_id', $shop->id)
            ->orderByDesc('skill_score')
            ->get(['id', 'name', 'level', 'skill_score', 'employment_type', 'status']);

        $rows = $employees->map(function ($emp) use ($entries) {
            $empEntries = $entries->where('employee_id', $emp->id);
            $shifts = $empEntries->count();
            $hours = $empEntries->sum(function ($e) {
                if (! $e->shiftTemplate) return 0;
                $start = strtotime("1970-01-01 {$e->shiftTemplate->start_time}");
                $end = strtotime("1970-01-01 {$e->shiftTemplate->end_time}");
                if ($end <= $start) $end += 86400;
                return ($end - $start) / 3600;
            });

            return [
                'employee_id' => $emp->id,
                'name' => $emp->name,
                'level' => $emp->level,
                'skill_score' => $emp->skill_score,
                'employment_type' => $emp->employment_type,
                'status' => $emp->status,
                'shifts_count' => $shifts,
                'total_hours' => round($hours, 1),
            ];
        });

        return response()->json([
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'rows' => $rows,
            'totals' => [
                'employees' => $rows->where('shifts_count', '>', 0)->count(),
                'shifts' => $entries->count(),
                'hours' => round($rows->sum('total_hours'), 1),
            ],
        ]);
    }

    public function shiftCoverage(Request $request): JsonResponse
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response()->json(['error' => 'No shop'], 404);
        }

        $weekStart = $this->resolveWeekStart($request->query('week'));

        $schedule = Schedule::where('shop_id', $shop->id)
            ->where('week_start_date', $weekStart->toDateString())
            ->first();

        $templates = ShiftTemplate::where('shop_id', $shop->id)
            ->where('is_active', true)
            ->with('requiredStations:id,name')
            ->orderBy('sort_order')
            ->get();

        // 預先撈整週所有 entries（含 employee.stations），避免 7×N 次 query
        $weekEntries = collect();
        if ($schedule) {
            $weekEntries = ScheduleEntry::where('schedule_id', $schedule->id)
                ->with(['employee:id,skill_score,level', 'employee.stations:id'])
                ->get()
                ->groupBy(fn ($e) => $e->shift_template_id.'|'.$e->date->toDateString());
        }

        $rows = $templates->map(function ($tpl) use ($weekEntries, $weekStart, $schedule) {
            $totalSlots = 0;
            $metScore = 0;
            $metSenior = 0;
            $metStations = 0;
            $hasStationReq = $tpl->requiredStations->isNotEmpty();

            for ($i = 0; $i < 7; $i++) {
                $dow = $weekStart->addDays($i)->dayOfWeek;
                if (! ($tpl->days_of_week_bitmask & (1 << $dow))) continue;
                $totalSlots++;
                if (! $schedule) continue;

                $date = $weekStart->addDays($i)->toDateString();
                $entries = $weekEntries->get($tpl->id.'|'.$date, collect());

                $totalScore = $entries->sum(fn ($e) => $e->employee?->skill_score ?? 0);
                $seniorCount = $entries
                    ->filter(fn ($e) => in_array($e->employee?->level, ['senior', 'lead'], true))
                    ->count();

                if ($totalScore >= $tpl->required_score) $metScore++;
                if ($seniorCount >= $tpl->min_senior_count) $metSenior++;

                if ($hasStationReq) {
                    $allCovered = $tpl->requiredStations->every(function ($station) use ($entries) {
                        $min = (int) ($station->pivot->min_count ?? 1);
                        $covered = $entries->filter(fn ($e) => $e->employee?->stations?->pluck('id')->contains($station->id))->count();
                        return $covered >= $min;
                    });
                    if ($allCovered) $metStations++;
                }
            }

            return [
                'shift_id' => $tpl->id,
                'shift_name' => $tpl->name,
                'time' => substr($tpl->start_time, 0, 5).'–'.substr($tpl->end_time, 0, 5),
                'required_score' => $tpl->required_score,
                'min_senior_count' => $tpl->min_senior_count,
                'total_slots' => $totalSlots,
                'met_score' => $metScore,
                'met_senior' => $metSenior,
                'met_stations' => $metStations,
                'has_station_req' => $hasStationReq,
                'coverage_score' => $totalSlots > 0 ? round($metScore / $totalSlots * 100) : 0,
                'coverage_senior' => $totalSlots > 0 ? round($metSenior / $totalSlots * 100) : 0,
                'coverage_stations' => $hasStationReq && $totalSlots > 0 ? round($metStations / $totalSlots * 100) : null,
            ];
        });

        return response()->json([
            'week_start' => $weekStart->toDateString(),
            'rows' => $rows,
        ]);
    }

    public function exportCsv(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $shop = Auth::user()?->resolveCurrentShop();
        if (! $shop) {
            return response('No shop', 404);
        }

        $weekStart = $this->resolveWeekStart($request->query('week'));

        $schedule = Schedule::where('shop_id', $shop->id)
            ->where('week_start_date', $weekStart->toDateString())
            ->first();

        $rows = [['日期', '時段', '員工', '職階', '分數', '時數', '薪資型態', '單價', '加倍', '預估薪資']];
        $totalHours = 0;
        $totalCost = 0;

        $weekEnd = $weekStart->addDays(6);
        $specialDates = Holiday::where('shop_id', $shop->id)
            ->where('type', 'special')
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        if ($schedule) {
            $entries = ScheduleEntry::where('schedule_id', $schedule->id)
                ->with(['employee:id,name,level,skill_score,hourly_wage,monthly_salary', 'shiftTemplate:id,name,start_time,end_time'])
                ->orderBy('date')
                ->get();

            // 月薪人員：本班次成本 = monthly_salary / (本月該員工總班數)
            // 為簡化先用：月薪 / 4 / 本週該員工班數 ≈ 該班次薪資佔比
            $monthlyEmpWeekShiftCount = [];
            foreach ($entries as $e) {
                $emp = $e->employee;
                if ($emp && $emp->monthly_salary && $emp->monthly_salary > 0) {
                    $monthlyEmpWeekShiftCount[$emp->id] = ($monthlyEmpWeekShiftCount[$emp->id] ?? 0) + 1;
                }
            }

            foreach ($entries as $e) {
                $tpl = $e->shiftTemplate;
                $hours = 0;
                if ($tpl) {
                    $start = strtotime("1970-01-01 {$tpl->start_time}");
                    $end = strtotime("1970-01-01 {$tpl->end_time}");
                    if ($end <= $start) $end += 86400;
                    $hours = round(($end - $start) / 3600, 1);
                }
                $emp = $e->employee;
                $salaryType = '';
                $unitPrice = '';
                $cost = 0;
                $isHoliday = in_array($e->date->toDateString(), $specialDates, true);
                $multiplier = $isHoliday ? 2.0 : 1.0;
                if ($emp) {
                    if ($emp->monthly_salary && $emp->monthly_salary > 0) {
                        $salaryType = '月薪';
                        $unitPrice = $emp->monthly_salary;
                        $shiftsThisWeek = $monthlyEmpWeekShiftCount[$emp->id] ?? 1;
                        $cost = (int) round(($emp->monthly_salary / 4) / $shiftsThisWeek * $multiplier);
                    } elseif ($emp->hourly_wage && $emp->hourly_wage > 0) {
                        $salaryType = '時薪';
                        $unitPrice = $emp->hourly_wage;
                        $cost = (int) round($hours * $emp->hourly_wage * $multiplier);
                    }
                }
                $totalHours += $hours;
                $totalCost += $cost;

                $rows[] = [
                    $e->date->toDateString(),
                    ($tpl?->name ?? '?').' '.substr($tpl?->start_time ?? '', 0, 5).'-'.substr($tpl?->end_time ?? '', 0, 5),
                    $emp?->name ?? '?',
                    $emp?->level ?? '',
                    $emp?->skill_score ?? 0,
                    $hours,
                    $salaryType,
                    $unitPrice,
                    $isHoliday ? '國定假日 ×2' : '',
                    $cost > 0 ? $cost : '',
                ];
            }

            $rows[] = ['', '', '', '', '合計', round($totalHours, 1), '', '', '', $totalCost];
        }

        $csv = "\xEF\xBB\xBF"; // UTF-8 BOM (給 Excel 認)
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $row))."\n";
        }

        $filename = "schedule-{$weekStart->toDateString()}.csv";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function resolveWeekStart(?string $weekParam): CarbonImmutable
    {
        if ($weekParam) {
            try {
                return CarbonImmutable::parse($weekParam)->startOfWeek(CarbonImmutable::MONDAY);
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY);
    }
}
