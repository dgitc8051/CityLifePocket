<?php

namespace App\Models;

use App\Support\Tenancy\IndirectBelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use IndirectBelongsToShop;

    protected string $shopVia = 'employee';

    protected $fillable = [
        'employee_id', 'start_datetime', 'end_datetime',
        'type', 'reason', 'attachment_url', 'status', 'source',
        'submitted_at', 'reviewed_by_user_id', 'reviewed_at',
        'review_note', 'line_notified_at',
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'line_notified_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
