<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = [
        'shop_id', 'user_id', 'binding_level',
        'system_role', 'permission_template_id', 'permission_overrides_json', 'is_admin_promoted',
        'name', 'phone', 'birthday',
        'line_user_id', 'skill_score', 'level', 'employment_type',
        'hire_date', 'leave_date', 'status',
        'weekly_max_hours', 'weekly_min_hours', 'daily_max_hours',
        'hourly_wage', 'monthly_salary',
        'notes',
    ];

    protected $casts = [
        'skill_score' => 'integer',
        'birthday' => 'date',
        'hire_date' => 'date',
        'leave_date' => 'date',
        'weekly_max_hours' => 'integer',
        'weekly_min_hours' => 'integer',
        'daily_max_hours' => 'integer',
        'hourly_wage' => 'integer',
        'monthly_salary' => 'integer',
        'permission_overrides_json' => 'array',
        'is_admin_promoted' => 'boolean',
    ];

    /**
     * 預設打卡密碼 = 生日 MMDD（例：1995-05-17 → 0517）
     * 沒設定生日的話回 null，要店家手動指定其他密碼
     */
    public function getDefaultAttendancePin(): ?string
    {
        return $this->birthday?->format('md');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function permissionTemplate(): BelongsTo
    {
        return $this->belongsTo(PermissionTemplate::class);
    }

    public function stations(): BelongsToMany
    {
        return $this->belongsToMany(Station::class, 'employee_stations')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function scheduleEntries(): HasMany
    {
        return $this->hasMany(ScheduleEntry::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(EmployeeAvailability::class);
    }

    public function defaultAvailabilities(): HasMany
    {
        return $this->hasMany(EmployeeDefaultAvailability::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function isSenior(): bool
    {
        return in_array($this->level, ['senior', 'lead'], true);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
