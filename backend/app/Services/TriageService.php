<?php

namespace App\Services;

use App\Models\RoutingRule;

class TriageService
{
    /**
     * P0 keywords: safety or total outage
     */
    private array $p0Keywords = [
        '困人', '卡住', '火災', '消防', '觸電', '漏電',
        '全站掛', '全部無法', '完全無法使用', '資料庫崩潰',
    ];

    /**
     * P1 keywords: core function failure
     */
    private array $p1Keywords = [
        '無法付款', '付款失敗', '無法登入', '登入失敗',
        '整棟', '全部使用者', '大量', '停電', '斷電',
        '無法存取', '伺服器錯誤',
    ];

    /**
     * Urgency keywords: bump severity by 1 level
     */
    private array $urgencyKeywords = [
        '緊急', '立刻', '馬上', '現在', '立即', '盡快',
    ];

    /**
     * High-priority categories that bump severity by 1 level
     */
    private array $highPriorityCategories = [
        'elevator', 'billing', 'auth',
    ];

    /**
     * Determine severity for an incident.
     *
     * @return array{severity: string, rule_matched: string}
     */
    public function evaluate(string $description, string $category): array
    {
        $severity = 'P3';
        $ruleMatched = 'default';

        // Check P0 keywords
        foreach ($this->p0Keywords as $keyword) {
            if (str_contains($description, $keyword)) {
                return [
                    'severity' => 'P0',
                    'rule_matched' => "keyword:{$keyword}",
                ];
            }
        }

        // Check P1 keywords
        foreach ($this->p1Keywords as $keyword) {
            if (str_contains($description, $keyword)) {
                $severity = 'P1';
                $ruleMatched = "keyword:{$keyword}";
                break;
            }
        }

        // Check routing rule priority weight
        $routingRule = RoutingRule::where('category', $category)->first();
        if ($routingRule && $routingRule->priority_weight >= 2 && $severity === 'P3') {
            $severity = 'P2';
            $ruleMatched = "category_weight:{$category}";
        }

        // Check high-priority categories -> bump 1 level
        if (in_array($category, $this->highPriorityCategories) && $severity === 'P3') {
            $severity = 'P2';
            $ruleMatched = "high_priority_category:{$category}";
        }

        // Check urgency keywords -> bump 1 level (but not to P0)
        foreach ($this->urgencyKeywords as $keyword) {
            if (str_contains($description, $keyword)) {
                $severity = $this->bumpSeverity($severity);
                $ruleMatched .= "+urgency:{$keyword}";
                break;
            }
        }

        return [
            'severity' => $severity,
            'rule_matched' => $ruleMatched,
        ];
    }

    private function bumpSeverity(string $current): string
    {
        return match ($current) {
            'P3' => 'P2',
            'P2' => 'P1',
            'P1' => 'P1', // Don't auto-bump to P0
            'P0' => 'P0',
        };
    }
}
