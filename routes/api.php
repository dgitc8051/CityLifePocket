<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\BusinessHoursController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SalaryMultiplierController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\ScheduleEntryController;
use App\Http\Controllers\Api\ShiftSwapRequestController;
use App\Http\Controllers\Api\ShiftTemplateController;
use App\Http\Controllers\Api\StationController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\ShopController;
use Illuminate\Support\Facades\Route;

// ---------- Public ----------
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/auth/me', [AuthController::class, 'me']);
Route::get('/auth/line-status', [\App\Http\Controllers\Auth\LineAuthController::class, 'status']);

// LINE Messaging API webhook(由 LINE 平台呼叫,signature 驗證)
Route::post('/line/webhook', [\App\Http\Controllers\Api\LineWebhookController::class, 'handle']);

// LIFF session 交換(public,內部會驗 LINE id_token)
Route::post('/liff/session', [\App\Http\Controllers\Api\LiffController::class, 'exchange']);

// ---------- Auth required ----------
Route::middleware('auth:sanctum')->group(function () {
    // 不需要 menu permission 的個人/帳號層級 endpoint
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/switch-shop', [AuthController::class, 'switchShop']);
    Route::post('/auth/bind-phone', [AuthController::class, 'bindPhone']);

    // ===== Dashboard =====
    Route::middleware('permission:dashboard,r')->get('/dashboard', [DashboardController::class, 'index']);

    // ===== Employees =====
    Route::middleware('permission:employees,r')->get('/employees', [EmployeeController::class, 'index']);
    Route::middleware('permission:employees,r')->get('/employees/{employee}', [EmployeeController::class, 'show']);
    Route::middleware('permission:employees,rw')->group(function () {
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);
    });

    // ===== Settings (店家資料 / 營業時間 / 公休 / Shop 設定) =====
    Route::middleware('permission:settings,r')->group(function () {
        Route::get('/shop', [ShopController::class, 'show']);
        Route::get('/business-hours', [BusinessHoursController::class, 'index']);
        Route::get('/holidays', [HolidayController::class, 'index']);
    });
    Route::middleware('permission:settings,rw')->group(function () {
        Route::put('/shop', [ShopController::class, 'update']);
        Route::put('/shop/line', [ShopController::class, 'updateLine']);
        Route::put('/shop/features', [ShopController::class, 'updateFeatures']);

        Route::put('/business-hours', [BusinessHoursController::class, 'bulkUpdate']);

        Route::post('/holidays', [HolidayController::class, 'store']);
        Route::put('/holidays/{holiday}', [HolidayController::class, 'update']);
        Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy']);
    });

    // ===== Shift Templates =====
    Route::middleware('permission:shift_templates,r')->group(function () {
        Route::get('/shift-templates', [ShiftTemplateController::class, 'index']);
        Route::get('/shift-templates/{shiftTemplate}', [ShiftTemplateController::class, 'show']);
    });
    Route::middleware('permission:shift_templates,rw')->group(function () {
        Route::post('/shift-templates', [ShiftTemplateController::class, 'store']);
        Route::put('/shift-templates/{shiftTemplate}', [ShiftTemplateController::class, 'update']);
        Route::delete('/shift-templates/{shiftTemplate}', [ShiftTemplateController::class, 'destroy']);
    });

    // ===== Schedule =====
    Route::middleware('permission:schedule,r')->get('/schedule', [ScheduleController::class, 'show']);
    Route::middleware('permission:schedule,rw')->group(function () {
        Route::post('/schedule/publish', [ScheduleController::class, 'publish']);
        Route::post('/schedule/copy', [ScheduleController::class, 'copyFromWeek']);
        Route::post('/schedule/clear', [ScheduleController::class, 'clearRange']);
        Route::post('/schedule/auto-generate/preview', [ScheduleController::class, 'autoGeneratePreview']);
        Route::post('/schedule/auto-generate/apply', [ScheduleController::class, 'autoGenerateApply']);
        Route::post('/schedule-entries', [ScheduleEntryController::class, 'store']);
        Route::delete('/schedule-entries/{entry}', [ScheduleEntryController::class, 'destroy']);
    });

    // ===== Leaves =====
    Route::middleware('permission:leaves,r')->group(function () {
        Route::get('/leaves', [LeaveRequestController::class, 'index']);
        Route::get('/leaves/{leaveRequest}', [LeaveRequestController::class, 'show']);
    });
    Route::middleware('permission:leaves,rw')->group(function () {
        Route::post('/leaves', [LeaveRequestController::class, 'store']);
        Route::put('/leaves/{leaveRequest}', [LeaveRequestController::class, 'update']);
        Route::delete('/leaves/{leaveRequest}', [LeaveRequestController::class, 'destroy']);
        Route::post('/leaves/{leaveRequest}/approve', [LeaveRequestController::class, 'approve']);
        Route::post('/leaves/{leaveRequest}/reject', [LeaveRequestController::class, 'reject']);
    });

    // ===== Availability =====
    Route::middleware('permission:availability,r')->group(function () {
        Route::get('/availability/matrix', [AvailabilityController::class, 'weeklyMatrix']);
        Route::get('/availability/defaults/{employee}', [AvailabilityController::class, 'getDefaults']);
    });
    Route::middleware('permission:availability,rw')->group(function () {
        Route::post('/availability', [AvailabilityController::class, 'submit']);
        Route::post('/availability/defaults/{employee}', [AvailabilityController::class, 'saveDefaults']);
        Route::post('/availability/apply-defaults', [AvailabilityController::class, 'applyDefaults']);
    });

    // ===== Reports =====
    Route::middleware('permission:reports,r')->group(function () {
        Route::get('/reports/weekly-hours', [ReportController::class, 'weeklyHours']);
        Route::get('/reports/shift-coverage', [ReportController::class, 'shiftCoverage']);
        Route::get('/reports/schedule.csv', [ReportController::class, 'exportCsv']);
    });

    // ===== Audit logs =====
    Route::middleware('permission:audit_logs,r')->get('/audit-logs', [AuditLogController::class, 'index']);

    // ===== Attendance / 打卡 =====(自己打卡每個人都可以 → 不掛 menu permission;
    // 但「刪除打卡紀錄」等管理動作要 attendance rw)
    Route::middleware('permission:attendance,r')->group(function () {
        Route::get('/attendance/today', [AttendanceController::class, 'todayStatus']);
        Route::get('/attendance/card-grid', [AttendanceController::class, 'cardGrid']);
        Route::get('/attendance', [AttendanceController::class, 'index']);
    });
    Route::middleware('permission:attendance,rw')->group(function () {
        Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn']);
        Route::post('/attendance/clock-with-pin', [AttendanceController::class, 'clockWithPin']);
        Route::post('/attendance/{record}/clock-out', [AttendanceController::class, 'clockOut']);
        Route::delete('/attendance/{record}', [AttendanceController::class, 'destroy']);
    });

    // 加班核可(feature: ot_approval + permission: attendance,rw)
    Route::middleware(['feature:ot_approval', 'permission:attendance,rw'])->group(function () {
        Route::get('/attendance/pending-overtime', [AttendanceController::class, 'pendingOvertime']);
        Route::post('/attendance/{record}/approve-overtime', [AttendanceController::class, 'approveOvertime']);
        Route::post('/attendance/{record}/reject-overtime', [AttendanceController::class, 'rejectOvertime']);
    });

    // 薪資 / 倍率 / 時數表 / 月結算(feature: payroll + permission: payroll)
    Route::middleware(['feature:payroll'])->group(function () {
        Route::middleware('permission:payroll,r')->group(function () {
            Route::get('/attendance/personal-hours', [AttendanceController::class, 'personalHours']);
            Route::get('/salary-multipliers', [SalaryMultiplierController::class, 'index']);
            Route::get('/payroll', [PayrollController::class, 'period']);
            Route::get('/payroll/{employee}/payslip', [PayrollController::class, 'payslip']);
        });
        Route::middleware('permission:payroll,rw')->group(function () {
            Route::post('/salary-multipliers', [SalaryMultiplierController::class, 'store']);
            Route::put('/salary-multipliers/{multiplier}', [SalaryMultiplierController::class, 'update']);
            Route::delete('/salary-multipliers/{multiplier}', [SalaryMultiplierController::class, 'destroy']);
            Route::post('/payroll/{period}/lock', [PayrollController::class, 'lock']);
            Route::post('/payroll/{period}/paid', [PayrollController::class, 'markPaid']);
        });
    });

    // Stations 站別(feature: stations + permission: settings)
    Route::middleware(['feature:stations'])->group(function () {
        Route::middleware('permission:settings,r')->get('/stations', [StationController::class, 'index']);
        Route::middleware('permission:settings,rw')->group(function () {
            Route::post('/stations', [StationController::class, 'store']);
            Route::put('/stations/{station}', [StationController::class, 'update']);
            Route::delete('/stations/{station}', [StationController::class, 'destroy']);
        });
    });

    // ===== Permission Templates =====
    Route::middleware('permission:permission_templates,r')->get('/permission-templates', [\App\Http\Controllers\Api\PermissionTemplateController::class, 'index']);
    Route::middleware('permission:permission_templates,rw')->group(function () {
        Route::post('/permission-templates', [\App\Http\Controllers\Api\PermissionTemplateController::class, 'store']);
        Route::put('/permission-templates/{permissionTemplate}', [\App\Http\Controllers\Api\PermissionTemplateController::class, 'update']);
        Route::delete('/permission-templates/{permissionTemplate}', [\App\Http\Controllers\Api\PermissionTemplateController::class, 'destroy']);
        Route::post('/permission-templates/{permissionTemplate}/apply', [\App\Http\Controllers\Api\PermissionTemplateController::class, 'applyToUsers']);
    });

    // ===== Shift swap requests(換班申請)=====
    Route::middleware('permission:shift_swaps,r')->get('/shift-swap-requests', [ShiftSwapRequestController::class, 'index']);
    Route::middleware('permission:shift_swaps,rw')->group(function () {
        Route::post('/shift-swap-requests', [ShiftSwapRequestController::class, 'store']);
        Route::post('/shift-swap-requests/{shiftSwapRequest}/approve', [ShiftSwapRequestController::class, 'approve']);
        Route::post('/shift-swap-requests/{shiftSwapRequest}/reject', [ShiftSwapRequestController::class, 'reject']);
        Route::delete('/shift-swap-requests/{shiftSwapRequest}', [ShiftSwapRequestController::class, 'destroy']);
    });

    // ===== 換班市場 (Coverage Market) =====
    Route::middleware('permission:coverage,r')->get('/coverage', [\App\Http\Controllers\Api\ShiftCoverageController::class, 'index']);
    Route::middleware('permission:coverage,rw')->group(function () {
        Route::post('/coverage', [\App\Http\Controllers\Api\ShiftCoverageController::class, 'store']);
        Route::delete('/coverage/{request}', [\App\Http\Controllers\Api\ShiftCoverageController::class, 'destroy']);
        Route::post('/coverage/{request}/accept/{offer}', [\App\Http\Controllers\Api\ShiftCoverageController::class, 'accept']);
    });

    // ---------- LIFF (員工端,登入後;不掛 menu permission,LIFF 本質就是個人介面)----------
    Route::get('/liff/me', [\App\Http\Controllers\Api\LiffController::class, 'me']);
    Route::get('/liff/attendance/state', [\App\Http\Controllers\Api\LiffAttendanceController::class, 'state']);
    Route::post('/liff/attendance/punch', [\App\Http\Controllers\Api\LiffAttendanceController::class, 'punch']);
    Route::get('/liff/schedule', [\App\Http\Controllers\Api\LiffScheduleController::class, 'mine']);

    Route::get('/liff/coverage/feed', [\App\Http\Controllers\Api\LiffCoverageController::class, 'feed']);
    Route::post('/liff/coverage/{coverageRequest}/offer', [\App\Http\Controllers\Api\LiffCoverageController::class, 'offer']);
    Route::post('/liff/coverage/offer/{offer}/withdraw', [\App\Http\Controllers\Api\LiffCoverageController::class, 'withdraw']);
});
