<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes gamification badges for a user based on their transaction history.
 */
class BadgeService
{
    public function __construct(
        private readonly FinancialSummaryService $summaryService,
        private readonly BudgetStatusService $budgetStatusService,
    ) {
    }

    /**
     * @return array{summary: array<string, mixed>, badges: array<int, array<string, mixed>>}
     */
    public function badges(User $user): array
    {
        $transactions    = $user->transactions()->get();
        $chart           = collect($this->summaryService->buildMonthlyChart($user, 6));
        $budgetRows      = collect($this->budgetStatusService->budgetStatus($user));

        $monthsPositive = $chart->where('balance', '>', 0)->count();
        $hasDeficit     = $chart->where('balance', '<', 0)->isNotEmpty();
        $totalTx        = $transactions->count();

        $distinctDaysThisMonth = $transactions
            ->whereBetween('transaction_date', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
            ->pluck('transaction_date')
            ->map(fn($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->count();

        $sideHustleIncome = $transactions
            ->where('type', 'income')
            ->filter(fn(Transaction $t) =>
                str_contains(strtolower($t->category), 'side') ||
                str_contains(strtolower($t->category), 'free')
            )->count();

        // Count comeback recoveries (deficit → positive)
        $comebackCount = 0;
        $wasDeficit    = false;
        foreach ($chart as $row) {
            if (($row['balance'] ?? 0) < 0) {
                $wasDeficit = true;
            } elseif ($wasDeficit && ($row['balance'] ?? 0) > 0) {
                $comebackCount++;
                $wasDeficit = false;
            }
        }

        // Current positive streak length
        $currentStreak = 0;
        foreach (array_reverse($chart->all()) as $row) {
            if (($row['balance'] ?? 0) > 0) {
                $currentStreak++;
            } else {
                break;
            }
        }

        $budgetKeeperMonths = $this->budgetStatusService->countBudgetKeeperMonths($user, 6);

        $firstSaverLevel    = $this->levelFromThresholds($monthsPositive,          [1, 2, 3, 4, 5, 6]);
        $savingStreakLevel   = $this->levelFromThresholds($currentStreak,           [1, 2, 3, 4, 5, 6]);
        $budgetKeeperLevel  = $this->levelFromThresholds($budgetKeeperMonths,      [1, 2, 3, 4, 5, 6]);
        $expenseTrackerLevel = $this->levelFromThresholds($totalTx,                [5, 20, 50, 100, 200, 500]);
        $sideHustlerLevel   = $this->levelFromThresholds($sideHustleIncome,        [1, 3, 5, 10, 20, 50]);
        $dailyLoggerLevel   = $this->levelFromThresholds($distinctDaysThisMonth,   [3, 7, 10, 15, 20, 25]);
        $comebackLevel      = $this->levelFromThresholds($comebackCount,           [1, 2, 3, 4, 5, 6]);

        $badges = [
            [
                'key'         => 'first_saver',
                'name'        => 'First Saver',
                'description' => 'Menandai progres saat kamu berhasil mulai menyisihkan uang.',
                'unlocked'    => $firstSaverLevel > 0,
                'level'       => $firstSaverLevel,
                'progress'    => $monthsPositive,
                'next_target' => $this->nextTarget($monthsPositive, [1, 2, 3, 4, 5, 6]),
            ],
            [
                'key'         => 'saving_streak',
                'name'        => 'Saving Streak',
                'description' => 'Menunjukkan konsistensi mempertahankan kebiasaan menabung.',
                'unlocked'    => $savingStreakLevel > 0,
                'level'       => $savingStreakLevel,
                'progress'    => $currentStreak,
                'next_target' => $this->nextTarget($currentStreak, [1, 2, 3, 4, 5, 6]),
            ],
            [
                'key'         => 'budget_keeper',
                'name'        => 'Budget Keeper',
                'description' => 'Menunjukkan kemampuan menjaga pengeluaran tetap sesuai budget.',
                'unlocked'    => $budgetKeeperLevel > 0,
                'level'       => $budgetKeeperLevel,
                'progress'    => $budgetKeeperMonths,
                'next_target' => $this->nextTarget($budgetKeeperMonths, [1, 2, 3, 4, 5, 6]),
            ],
            [
                'key'         => 'expense_tracker',
                'name'        => 'Expense Tracker',
                'description' => 'Membantu melihat konsistensi mencatat pengeluaran.',
                'unlocked'    => $expenseTrackerLevel > 0,
                'level'       => $expenseTrackerLevel,
                'progress'    => $totalTx,
                'next_target' => $this->nextTarget($totalTx, [5, 20, 50, 100, 200, 500]),
            ],
            [
                'key'         => 'side_hustler',
                'name'        => 'Side Hustler',
                'description' => 'Menandai progres eksplorasi peluang penghasilan tambahan.',
                'unlocked'    => $sideHustlerLevel > 0,
                'level'       => $sideHustlerLevel,
                'progress'    => $sideHustleIncome,
                'next_target' => $this->nextTarget($sideHustleIncome, [1, 3, 5, 10, 20, 50]),
            ],
            [
                'key'         => 'daily_logger',
                'name'        => 'Daily Logger',
                'description' => 'Mengukur kebiasaan rutin mencatat aktivitas keuangan.',
                'unlocked'    => $dailyLoggerLevel > 0,
                'level'       => $dailyLoggerLevel,
                'progress'    => $distinctDaysThisMonth,
                'next_target' => $this->nextTarget($distinctDaysThisMonth, [3, 7, 10, 15, 20, 25]),
            ],
            [
                'key'         => 'comeback',
                'name'        => 'Comeback',
                'description' => 'Mengapresiasi saat kamu kembali aktif mengelola keuangan.',
                'unlocked'    => $comebackLevel > 0,
                'level'       => $comebackLevel,
                'progress'    => $comebackCount,
                'next_target' => $this->nextTarget($comebackCount, [1, 2, 3, 4, 5, 6]),
            ],
        ];

        return [
            'summary' => [
                'unlocked_count' => collect($badges)->where('unlocked', true)->count(),
                'total_badges'   => count($badges),
                'max_level'      => 6,
            ],
            'badges' => $badges,
        ];
    }

    private function levelFromThresholds(int $value, array $thresholds): int
    {
        $level = 0;
        foreach ($thresholds as $threshold) {
            if ($value >= $threshold) {
                $level++;
            } else {
                break;
            }
        }
        return $level;
    }

    private function nextTarget(int $value, array $thresholds): ?int
    {
        foreach ($thresholds as $threshold) {
            if ($value < $threshold) {
                return $threshold;
            }
        }
        return null;
    }
}
