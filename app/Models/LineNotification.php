<?php

namespace App\Models;

use App\Support\Tenancy\IndirectBelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LineNotification extends Model
{
    use \App\Support\Tenancy\BelongsToShop;

    protected $table = 'line_notifications';

    protected $fillable = [
        'shop_id', 'user_id', 'employee_id',
        'type', 'direction', 'template_key', 'idempotency_key',
        'payload_json', 'sent_at', 'scheduled_at',
        'line_message_id', 'status', 'retry_count', 'error_message',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'sent_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
