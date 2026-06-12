<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * 掃描程式碼,找出可能繞過 tenant scope 的手動過濾 / 危險 query。
 *
 * 用法:php artisan tenancy:audit
 *      php artisan tenancy:audit --fail-on-finding   (CI 用,有問題 exit 1)
 *
 * 規則:
 *   1. controller 內手動寫 where('shop_id', ...) → 多餘 / 應該移除(scope 會處理)
 *   2. Model::query() 後沒接 scope 也沒接 withoutShopScope → 提醒檢查
 *   3. whereHas + shop_id 在 controller 裡 → 改用 model 的 IndirectShopScope
 *   4. withoutShopScope / TenantContext::bypass → 列出來給人工 review(這些是有意繞過)
 */
class TenancyAuditCommand extends Command
{
    protected $signature = 'tenancy:audit {--fail-on-finding : exit 1 if any findings}';
    protected $description = '掃描 controller / service 是否還有手動 shop_id 過濾 / 可能漏網的 query';

    private array $findings = [];

    public function handle(): int
    {
        $this->info('🔍 掃描 app/Http/Controllers 與 app/Services...');

        $finder = new Finder();
        $finder->files()
            ->in([base_path('app/Http/Controllers'), base_path('app/Services'), base_path('app/Jobs')])
            ->name('*.php');

        foreach ($finder as $file) {
            $rel = str_replace(base_path().'/', '', $file->getRealPath());
            $content = $file->getContents();
            $lines = explode("\n", $content);

            foreach ($lines as $no => $line) {
                $this->scanLine($rel, $no + 1, $line);
            }
        }

        $this->renderReport();

        $hasIssues = count($this->severityCount(['warn', 'info'])) > 0;

        if ($this->option('fail-on-finding') && count($this->findings) > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function scanLine(string $file, int $line, string $code): void
    {
        $trim = trim($code);

        // 跳過註解、空行
        if ($trim === '' || str_starts_with($trim, '//') || str_starts_with($trim, '*') || str_starts_with($trim, '#')) {
            return;
        }

        // 規則 1:手動 where('shop_id', ...) — 可能與 global scope 重複
        if (preg_match("/where\(['\"]shop_id['\"]\s*,/", $code)) {
            $this->add('warn', $file, $line, '手動 shop_id 過濾', '可考慮移除,Global ShopScope 已自動處理');
        }

        // 規則 2:whereHas + shop_id — controller 不該直接做這事
        if (preg_match("/whereHas\(['\"](employee|schedule|fromEmployee|toEmployee)['\"]/", $code)
            && (str_contains($code, 'shop_id') || $this->nextLineHasShopId($file, $line))) {
            $this->add('warn', $file, $line, '手動 whereHas + shop_id', '改用 IndirectBelongsToShop trait 自動處理');
        }

        // 規則 3:withoutShopScope / bypass — 有意繞過,列出來給 review
        if (preg_match('/withoutShopScope|TenantContext::bypass/', $code)) {
            $this->add('info', $file, $line, '顯式繞過 ShopScope', '確認這是有意的(跨店報表 / 系統工作)');
        }

        // 規則 4:DB::table 直接查 → 完全繞過 Eloquent → 沒有 scope 保護
        if (preg_match('/DB::table\([\'"](employees|schedules|schedule_entries|shift_templates|attendance_records|leave_requests|shift_swap_requests|stations|business_hours|holidays|line_notifications)[\'"]/', $code)) {
            $this->add('warn', $file, $line, 'DB::table 直接查 tenant 表', '改用 Eloquent model 才會套用 ShopScope');
        }

        // 規則 5:Model::find($id) 沒檢查 shop ownership — 重大,但難精準偵測。先警告 ::find 用法
        if (preg_match('/(Employee|Schedule|ShiftTemplate|AttendanceRecord|LeaveRequest)::find\(/', $code)) {
            $this->add('info', $file, $line, '::find($id) 用法', '搭配 ShopScope 已會自動過濾 — 確認 caller 信任 id');
        }
    }

    private function nextLineHasShopId(string $file, int $line): bool
    {
        $lines = file(base_path($file));
        return isset($lines[$line]) && str_contains($lines[$line], 'shop_id');
    }

    private function add(string $severity, string $file, int $line, string $issue, string $hint): void
    {
        $this->findings[] = compact('severity', 'file', 'line', 'issue', 'hint');
    }

    private function renderReport(): void
    {
        if (empty($this->findings)) {
            $this->info('✅ 沒有發現可疑模式');
            return;
        }

        // 依 file 分組,severity 排序
        $byFile = collect($this->findings)->groupBy('file');
        $this->newLine();
        $this->line('<comment>=== Tenancy Audit Report ===</comment>');
        $this->newLine();

        foreach ($byFile as $file => $items) {
            $this->line("<info>📄 {$file}</info>");
            foreach ($items as $f) {
                $tag = match ($f['severity']) {
                    'warn' => '<fg=yellow>⚠ WARN</>',
                    'info' => '<fg=blue>ℹ INFO</>',
                    default => '<fg=red>✗</>',
                };
                $this->line("  {$tag}  L{$f['line']}  {$f['issue']}");
                $this->line("         └─ {$f['hint']}");
            }
            $this->newLine();
        }

        $stats = $this->severityCount(['warn', 'info']);
        $this->line('合計 '.array_sum($stats).' 筆 (warn='.($stats['warn'] ?? 0).', info='.($stats['info'] ?? 0).')');
    }

    private function severityCount(array $keep): array
    {
        $out = [];
        foreach ($this->findings as $f) {
            if (! in_array($f['severity'], $keep, true)) continue;
            $out[$f['severity']] = ($out[$f['severity']] ?? 0) + 1;
        }
        return $out;
    }
}
