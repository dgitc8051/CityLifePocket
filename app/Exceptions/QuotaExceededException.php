<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * 訂閱方案配額超出時拋。
 *
 * 範例:
 *   throw QuotaExceededException::forShop($org);
 *   throw QuotaExceededException::forSeat($org);
 */
class QuotaExceededException extends RuntimeException
{
    public string $quotaType;
    public int $currentCount;
    public int $limit;

    public function __construct(string $message, string $quotaType, int $current, int $limit, ?Throwable $previous = null)
    {
        parent::__construct($message, 402, $previous);
        $this->quotaType = $quotaType;
        $this->currentCount = $current;
        $this->limit = $limit;
    }

    public static function forShop(int $current, int $limit, string $planName = ''): self
    {
        return new self(
            "店數已達方案上限({$current}/{$limit})。請升級方案或聯絡客服。",
            'shop',
            $current,
            $limit,
        );
    }

    public static function forSeat(int $current, int $limit, string $planName = ''): self
    {
        return new self(
            "員工數已達方案上限({$current}/{$limit})。請升級方案或聯絡客服。",
            'seat',
            $current,
            $limit,
        );
    }

    public function toArray(): array
    {
        return [
            'error' => $this->getMessage(),
            'quota_type' => $this->quotaType,
            'current' => $this->currentCount,
            'limit' => $this->limit,
        ];
    }
}
