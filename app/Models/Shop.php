<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shop extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'brand_id', 'name', 'owner_user_id', 'timezone', 'settings_json',
        // 打卡地理圍籬
        'clock_in_lat', 'clock_in_lng', 'clock_in_radius_m',
        // LINE Messaging API (Bot)
        'line_channel_id', 'line_channel_secret_encrypted',
        'line_messaging_access_token_encrypted', 'line_bot_user_id',
        // LINE Login
        'line_login_channel_id', 'line_login_channel_secret_encrypted',
        // LIFF
        'line_liff_id',
    ];

    protected $casts = [
        'settings_json' => 'array',
        'clock_in_lat' => 'decimal:7',
        'clock_in_lng' => 'decimal:7',
        'clock_in_radius_m' => 'integer',
        // Laravel encrypted cast：寫入時自動加密、讀取時自動解密
        // 雖然欄位名有 _encrypted 後綴，但 accessor 拿到的是明文
        'line_channel_secret_encrypted' => 'encrypted',
        'line_login_channel_secret_encrypted' => 'encrypted',
        'line_messaging_access_token_encrypted' => 'encrypted',
    ];

    protected $hidden = [
        // API response 預設不回傳這些祕密欄位
        'line_channel_secret_encrypted',
        'line_login_channel_secret_encrypted',
        'line_messaging_access_token_encrypted',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function businessHours(): HasMany
    {
        return $this->hasMany(BusinessHour::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function salaryMultipliers(): HasMany
    {
        return $this->hasMany(ShopSalaryMultiplier::class)->orderBy('sort_order');
    }

    public function shiftTemplates(): HasMany
    {
        return $this->hasMany(ShiftTemplate::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function availabilitySettings(): HasOne
    {
        return $this->hasOne(AvailabilityCollectionSetting::class);
    }

    /**
     * 功能開關預設值（settings_json.features.<key> 沒設時的 fallback）。
     * 新店預設「全部開」— 已用功能不希望突然消失；店家可自行關閉。
     */
    public const FEATURE_DEFAULTS = [
        'stations' => true,           // 站別系統
        'senior_required' => true,    // 班次「最少高階員工」門檻
        'skill_score' => true,        // 能力分數（1-10 + required_score）
        'payroll' => true,            // 時薪 / 月薪 / 薪資倍率 / 時數表
        'ot_approval' => true,        // 加班需店家核可才計薪
    ];

    /**
     * 讀取單一功能開關。寫設定時用 settings_json.features.<key>。
     */
    public function feature(string $key): bool
    {
        $features = $this->settings_json['features'] ?? [];
        return (bool) ($features[$key] ?? self::FEATURE_DEFAULTS[$key] ?? false);
    }

    /**
     * 全部 features 的目前值（給前端用）
     *
     * @return array<string, bool>
     */
    public function features(): array
    {
        $features = $this->settings_json['features'] ?? [];
        $out = [];
        foreach (self::FEATURE_DEFAULTS as $key => $default) {
            $out[$key] = (bool) ($features[$key] ?? $default);
        }
        return $out;
    }

    /**
     * LINE 整合是否已設定（最少要有 messaging channel）。
     */
    public function hasLineConfigured(): bool
    {
        return ! empty($this->line_channel_id);
    }

    /**
     * LINE Login 是否已設定（用於員工 LIFF 登入）。
     */
    public function hasLineLoginConfigured(): bool
    {
        return ! empty($this->line_login_channel_id) && ! empty($this->line_login_channel_secret_encrypted);
    }
}
