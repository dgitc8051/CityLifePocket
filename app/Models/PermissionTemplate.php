<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 權限模板。
 *
 * is_system=true:全系統內建(店家擁有者 / 店長 / 副店長 / 員工)
 *   organization_id 必為 null。
 *   不能刪除、不能改名字,但可以「另存為」當作起點。
 *
 * 一般 (is_system=false):某個 organization 自訂的模板。
 *   organization_id 必為該 org id。
 *   只有該 org 的 admin/owner 看得到。
 */
class PermissionTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'description',
        'permissions_json', 'is_system', 'sort_order',
    ];

    protected $casts = [
        'permissions_json' => 'array',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * 模板對某 menu key 的權限值(rw / r / none)
     */
    public function permissionFor(string $key): string
    {
        return $this->permissions_json[$key] ?? 'none';
    }

    /**
     * 是否可被某 user 看到並套用。
     *  - admin:全可
     *  - 同 org 的非 admin:只看 is_system + 自己 org 的
     */
    public function visibleTo(User $user): bool
    {
        if ($user->isAdmin()) return true;
        if ($this->is_system) return true;
        return $this->organization_id === $user->organization_id;
    }
}
