<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FinancialInsightService
{
    public function __construct(private readonly MlGatewayService $mlGateway)
    {
    }

    public function dashboard(User $user): array
    {
        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfMonth();

        $monthlyTransactions = $user->transactions()
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $income = $monthlyTransactions->where('type', 'income')->sum('amount');
        $expense = $monthlyTransactions->where('type', 'expense')->sum('amount');
        $balance = $income - $expense;
        $savingRate = $income > 0 ? ($balance / $income) * 100 : 0;

        return [
            'summary' => [
                'income' => round($income, 2),
                'expense' => round($expense, 2),
                'balance' => round($balance, 2),
                'saving_rate' => round($savingRate, 2),
            ],
            'monthly_chart' => $this->buildMonthlyChart($user, 6),
            'recent_transactions' => $user->transactions()
                ->latest('transaction_date')
                ->latest('id')
                ->limit(5)
                ->get(),
            'budget_snapshot' => $this->budgetStatus($user),
            'latest_classification' => optional($user->assessments()->latest()->first())->classification,
        ];
    }

    public function profile(User $user): array
    {
        $dashboard = $this->dashboard($user);
        $monthly = $dashboard['summary'];
        $expenseRatio = $monthly['income'] > 0 ? $monthly['expense'] / $monthly['income'] : 1;
        $latestAssessment = $user->assessments()->latest()->first();

        if ($expenseRatio > 0.85) {
            $spendingHabit = 'agresif';
        } elseif ($expenseRatio > 0.65) {
            $spendingHabit = 'moderat';
        } else {
            $spendingHabit = 'terkendali';
        }

        $monthlyChart = collect($this->buildMonthlyChart($user, 4));
        $incomeTrend = $monthlyChart->pluck('income')->values();
        $incomePattern = $this->determineIncomePattern($incomeTrend);

        $savingBehavior = 'perlu perbaikan';
        if ($monthly['saving_rate'] >= 25) {
            $savingBehavior = 'konsisten';
        } elseif ($monthly['saving_rate'] > 0) {
            $savingBehavior = 'berkembang';
        }

        $warnings = [];
        foreach ($this->budgetStatus($user) as $budgetRow) {
            if ($budgetRow['is_overbudget']) {
                $warnings[] = 'Kategori ' . $budgetRow['category'] . ' sudah melewati batas budget.';
            }
        }
        if ($monthly['balance'] < 0) {
            $warnings[] = 'Cashflow bulan ini negatif. Pangkas pengeluaran non-esensial.';
        }

        $nextMonthPrediction = $this->estimateNextMonthBalance($monthlyChart, $monthly);

        $recommendations = [];
        if ($spendingHabit === 'agresif') {
            $recommendations[] = 'Tetapkan limit mingguan untuk pengeluaran fleksibel.';
        }
        if ($savingBehavior !== 'konsisten') {
            $recommendations[] = 'Aktifkan auto-transfer tabungan setiap hari gajian.';
        }
        if ($incomePattern === 'menurun') {
            $recommendations[] = 'Prioritaskan side hustle dengan waktu mulai cepat.';
        }
        if (empty($recommendations)) {
            $recommendations[] = 'Pertahankan ritme sekarang dan evaluasi target 30 hari ke depan.';
        }

        $prediction = $this->dailyPrediction($user, $monthly, $latestAssessment, $nextMonthPrediction);

        return [
            'spending_habit' => $spendingHabit,
            'income_pattern' => $incomePattern,
            'saving_behavior' => $savingBehavior,
            'warnings' => $warnings,
            'prediction' => $prediction,
            'recommendations' => array_values(array_unique(array_merge(
                $recommendations,
                $prediction['recommendations'] ?? [],
            ))),
        ];
    }

    private function dailyPrediction(User $user, array $monthly, mixed $latestAssessment, float $fallbackBalance): array
    {
        $dateKey = Carbon::now()->toDateString();
        $cacheKey = "finary:predict:{$user->id}:{$dateKey}";

        return Cache::remember($cacheKey, Carbon::now()->endOfDay(), function () use ($user, $monthly, $latestAssessment, $fallbackBalance, $dateKey) {
            // Use assessment data as baseline, overlay with actual transaction data if available
            $assessmentIncome = (float) ($latestAssessment?->monthly_income ?? 0);
            $assessmentExpense = (float) ($latestAssessment?->monthly_expense ?? 0);

            // Prefer actual transaction data, but only if user has meaningful data this month
            $hasTransactions = $monthly['income'] > 0 || $monthly['expense'] > 0;
            $income = $hasTransactions ? (float) $monthly['income'] : $assessmentIncome;
            $expense = $hasTransactions ? (float) $monthly['expense'] : $assessmentExpense;

            // If mid-month and only expense recorded (no income yet), blend with assessment
            // to avoid false alarm from incomplete month data
            if ($hasTransactions && $income == 0 && $expense > 0 && $assessmentIncome > 0) {
                $income = $assessmentIncome;
            }

            $actualBalance = $income - $expense;
            $savings = (float) ($latestAssessment?->actual_savings ?? max($actualBalance, 0));

            $payload = [
                'income' => $income,
                'expense' => $expense,
                'savings' => $savings,
                'target_tabungan' => (float) ($latestAssessment?->budget_goal ?? 0),
                'loan_payment' => (float) ($latestAssessment?->loan_payment ?? 0),
                'emergency_fund' => (float) ($latestAssessment?->emergency_fund ?? 0),
            ];

            $mlResult = $this->mlGateway->predictInsight($payload);

            if (
                is_array($mlResult)
                && array_key_exists('predicted_next_month_balance', $mlResult)
                && array_key_exists('warning_probability', $mlResult)
                && array_key_exists('warning_flag', $mlResult)
            ) {
                $predictedBalance = (float) $mlResult['predicted_next_month_balance'];
                $bounds = $this->predictionBounds($payload);
                $adjustedBalance = min(max($predictedBalance, $bounds['min']), $bounds['max']);

                if ($adjustedBalance !== $predictedBalance) {
                    Log::warning('ML prediction out of expected range; clamping', [
                        'predicted' => $predictedBalance,
                        'adjusted' => $adjustedBalance,
                        'min' => $bounds['min'],
                        'max' => $bounds['max'],
                        'payload' => $payload,
                    ]);
                }

                // Validate ML warning_probability is in 0-1 range
                $mlWarningProb = (float) $mlResult['warning_probability'];
                $mlWarningProb = min(1.0, max(0.0, $mlWarningProb));
                $warningFlag = (int) $mlResult['warning_flag'];

                return [
                    'next_month_balance' => round($adjustedBalance, 2),
                    'warning_probability' => round($mlWarningProb, 4),
                    'warning_flag' => $warningFlag,
                    'warning_text' => $this->buildWarningText($warningFlag, $mlWarningProb, $adjustedBalance),
                    'recommendations' => array_values($mlResult['recommendations'] ?? []),
                    'currency' => 'IDR',
                    'source' => 'ml',
                    'generated_for' => $dateKey,
                    'payload' => $payload,
                ];
            }

            // --- Rule-based fallback with improved logic ---
            $expenseRatio = $income > 0 ? $expense / $income : 0.5;
            $loanBurden = ($income > 0 && $payload['loan_payment'] > 0)
                ? $payload['loan_payment'] / $income
                : 0;

            // Multi-factor warning probability
            $warningProbability = 0.0;
            $warningProbability += min(0.45, $expenseRatio * 0.45);  // expense ratio contributes up to 45%
            $warningProbability += min(0.20, $loanBurden * 0.60);    // loan burden contributes up to 20%

            // Savings buffer reduces warning
            $savingsBuffer = ($income > 0 && $savings > 0) ? min(0.15, ($savings / $income) * 0.15) : 0;
            $warningProbability -= $savingsBuffer;

            // Emergency fund reduces warning
            $emergencyBuffer = ($expense > 0 && $payload['emergency_fund'] >= $expense * 3) ? 0.10 : 0;
            $warningProbability -= $emergencyBuffer;

            $warningProbability = round(min(0.95, max(0.05, $warningProbability)), 4);

            // Better next month balance prediction: use weighted recent history
            $monthlyChart = collect($this->buildMonthlyChart($user, 3));
            $nonZeroBalances = $monthlyChart->pluck('balance')->filter(fn($b) => $b != 0);
            if ($nonZeroBalances->isNotEmpty()) {
                // Weighted average: recent months matter more
                $weights = [];
                $values = $nonZeroBalances->values()->all();
                $count = count($values);
                for ($i = 0; $i < $count; $i++) {
                    $weights[] = $i + 1; // 1, 2, 3... (latest gets highest weight)
                }
                $weightedSum = 0;
                $weightTotal = array_sum($weights);
                for ($i = 0; $i < $count; $i++) {
                    $weightedSum += $values[$i] * $weights[$i];
                }
                $predictedBalance = round($weightedSum / $weightTotal, 2);
            } else {
                // No transaction history, estimate from assessment
                $predictedBalance = round($income - $expense, 2);
            }

            $warningFlag = ($warningProbability >= 0.55 || $predictedBalance < 0) ? 1 : 0;

            return [
                'next_month_balance' => $predictedBalance,
                'warning_probability' => $warningProbability,
                'warning_flag' => $warningFlag,
                'warning_text' => $this->buildWarningText($warningFlag, $warningProbability, $predictedBalance),
                'recommendations' => $this->buildPredictionRecommendations($expenseRatio, $predictedBalance, $loanBurden, $savingsBuffer),
                'currency' => 'IDR',
                'source' => 'rule-based',
                'generated_for' => $dateKey,
                'payload' => $payload,
            ];
        });
    }

    private function buildWarningText(int $warningFlag, float $warningProbability, float $predictedBalance): string
    {
        if ($warningFlag === 0) {
            return 'Kondisi keuangan bulan depan diprediksi terkendali. Pertahankan pola pengeluaran saat ini.';
        }

        $texts = [];

        if ($predictedBalance < 0) {
            $texts[] = 'Saldo bulan depan diprediksi negatif — prioritaskan pemangkasan pengeluaran variabel segera.';
        } elseif ($warningProbability >= 0.75) {
            $texts[] = 'Risiko defisit bulan depan sangat tinggi (' . round($warningProbability * 100) . '%). Tinjau pengeluaran besar sekarang.';
        } else {
            $texts[] = 'Ada indikasi tekanan keuangan bulan depan (' . round($warningProbability * 100) . '%). Kurangi pengeluaran non-esensial.';
        }

        return implode(' ', $texts);
    }

    private function buildPredictionRecommendations(float $expenseRatio, float $predictedBalance, float $loanBurden, float $savingsBuffer): array
    {
        $recs = [];

        if ($predictedBalance < 0) {
            $recs[] = 'Saldo bulan depan diprediksi negatif. Prioritaskan pemangkasan pengeluaran variabel.';
        }

        if ($expenseRatio >= 0.8) {
            $recs[] = 'Rasio pengeluaran tinggi (' . round($expenseRatio * 100) . '%). Evaluasi pengeluaran non-esensial.';
        }

        if ($loanBurden >= 0.3) {
            $recs[] = 'Beban cicilan cukup besar. Pertimbangkan restrukturisasi atau percepatan pelunasan.';
        }

        if ($savingsBuffer < 0.05) {
            $recs[] = 'Buffer tabungan masih tipis. Sisihkan minimal 10% income untuk tabungan darurat.';
        }

        if (empty($recs)) {
            $recs[] = 'Kondisi keuangan cukup stabil. Jaga konsistensi dan review target bulanan.';
        }

        return $recs;
    }

    public function budgetStatus(User $user): array
    {
        $period = Carbon::now()->format('Y-m');
        $budgets = $user->budgets()->where('period', $period)->get();

        return $budgets->map(function (Budget $budget) use ($user, $period) {
            $periodDate = Carbon::createFromFormat('Y-m', $period);
            $spent = (float) $user->transactions()
                ->where('type', 'expense')
                ->where('category', $budget->category)
                ->whereBetween('transaction_date', [
                    $periodDate->copy()->startOfMonth()->toDateString(),
                    $periodDate->copy()->endOfMonth()->toDateString(),
                ])
                ->sum('amount');

            $remaining = max(0, $budget->monthly_limit - $spent);
            $progress = $budget->monthly_limit > 0 ? ($spent / $budget->monthly_limit) * 100 : 0;

            return [
                'id' => $budget->id,
                'category' => $budget->category,
                'period' => $budget->period,
                'monthly_limit' => (float) $budget->monthly_limit,
                'spent' => round($spent, 2),
                'remaining' => round($remaining, 2),
                'progress_percent' => round($progress, 2),
                'is_overbudget' => $spent > $budget->monthly_limit,
            ];
        })->values()->all();
    }

    public function badges(User $user): array
    {
        $transactions = $user->transactions()->get();
        $chart = collect($this->buildMonthlyChart($user, 6));
        $budgetRows = collect($this->budgetStatus($user));

        // ── Raw counts ────────────────────────────────────────────────────────
        $monthsPositive  = $chart->where('balance', '>', 0)->count();
        $hasDeficit      = $chart->where('balance', '<', 0)->isNotEmpty();
        $latestPositive  = ($chart->last()['balance'] ?? 0) > 0;
        $totalTx         = $transactions->count();

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

        // Count how many times user recovered from deficit (comeback count)
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

        // Budget keeper: count consecutive months with no overbudget
        // We approximate using the 6-month chart — check each month's budget
        $budgetKeeperMonths = $this->countBudgetKeeperMonths($user, 6);

        // ── Level calculators (1-6, 0 = locked) ──────────────────────────────
        $firstSaverLevel   = $this->levelFromThresholds($monthsPositive,  [1, 2, 3, 4, 5, 6]);
        $savingStreakLevel  = $this->levelFromThresholds($currentStreak,   [1, 2, 3, 4, 5, 6]);
        $budgetKeeperLevel = $this->levelFromThresholds($budgetKeeperMonths, [1, 2, 3, 4, 5, 6]);
        $expenseTrackerLevel = $this->levelFromThresholds($totalTx,        [5, 20, 50, 100, 200, 500]);
        $sideHustlerLevel  = $this->levelFromThresholds($sideHustleIncome, [1, 3, 5, 10, 20, 50]);
        $dailyLoggerLevel  = $this->levelFromThresholds($distinctDaysThisMonth, [3, 7, 10, 15, 20, 25]);
        $comebackLevel     = $this->levelFromThresholds($comebackCount,    [1, 2, 3, 4, 5, 6]);

        $badges = [
            [
                'key'         => 'first_saver',
                'name'        => 'First Saver',
                'description' => 'Raih cashflow positif setiap bulan. Lv1=1 bln, Lv6=6 bln.',
                'unlocked'    => $firstSaverLevel > 0,
                'level'       => $firstSaverLevel,
                'progress'    => $monthsPositive,
                'next_target' => $this->nextTarget($monthsPositive, [1, 2, 3, 4, 5, 6]),
            ],
            [
                'key'         => 'saving_streak',
                'name'        => 'Saving Streak',
                'description' => 'Jaga saldo positif berturut-turut. Lv1=1 bln, Lv6=6 bln beruntun.',
                'unlocked'    => $savingStreakLevel > 0,
                'level'       => $savingStreakLevel,
                'progress'    => $currentStreak,
                'next_target' => $this->nextTarget($currentStreak, [1, 2, 3, 4, 5, 6]),
            ],
            [
                'key'         => 'budget_keeper',
                'name'        => 'Budget Keeper',
                'description' => 'Tidak overbudget di semua kantong. Lv1=1 bln, Lv6=6 bln berturut.',
                'unlocked'    => $budgetKeeperLevel > 0,
                'level'       => $budgetKeeperLevel,
                'progress'    => $budgetKeeperMonths,
                'next_target' => $this->nextTarget($budgetKeeperMonths, [1, 2, 3, 4, 5, 6]),
            ],
            [
                'key'         => 'expense_tracker',
                'name'        => 'Expense Tracker',
                'description' => 'Catat transaksi secara konsisten. Lv1=5, Lv2=20, Lv3=50, Lv4=100, Lv5=200, Lv6=500.',
                'unlocked'    => $expenseTrackerLevel > 0,
                'level'       => $expenseTrackerLevel,
                'progress'    => $totalTx,
                'next_target' => $this->nextTarget($totalTx, [5, 20, 50, 100, 200, 500]),
            ],
            [
                'key'         => 'side_hustler',
                'name'        => 'Side Hustler',
                'description' => 'Kumpulkan pemasukan dari side hustle/freelance. Lv1=1, Lv2=3, Lv3=5, Lv4=10, Lv5=20, Lv6=50.',
                'unlocked'    => $sideHustlerLevel > 0,
                'level'       => $sideHustlerLevel,
                'progress'    => $sideHustleIncome,
                'next_target' => $this->nextTarget($sideHustleIncome, [1, 3, 5, 10, 20, 50]),
            ],
            [
                'key'         => 'daily_logger',
                'name'        => 'Daily Logger',
                'description' => 'Aktif mencatat tiap hari bulan ini. Lv1=3 hari, Lv2=7, Lv3=10, Lv4=15, Lv5=20, Lv6=25.',
                'unlocked'    => $dailyLoggerLevel > 0,
                'level'       => $dailyLoggerLevel,
                'progress'    => $distinctDaysThisMonth,
                'next_target' => $this->nextTarget($distinctDaysThisMonth, [3, 7, 10, 15, 20, 25]),
            ],
            [
                'key'         => 'comeback',
                'name'        => 'Comeback',
                'description' => 'Bangkit dari defisit ke surplus. Lv1=1x, Lv6=6x.',
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

    /**
     * Returns level 1-6 based on which threshold the value has crossed.
     * Returns 0 if below the first threshold (locked).
     */
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

    /**
     * Returns the next threshold the user needs to reach, or null if maxed out.
     */
    private function nextTarget(int $value, array $thresholds): ?int
    {
        foreach ($thresholds as $threshold) {
            if ($value < $threshold) {
                return $threshold;
            }
        }
        return null;
    }

    /**
     * Count consecutive months (up to $months back) where user had budgets
     * and none were overbudget. Stops counting at first month with overbudget.
     */
    private function countBudgetKeeperMonths(User $user, int $months): int
    {
        $count = 0;
        for ($i = 0; $i < $months; $i++) {
            $month      = Carbon::now()->startOfMonth()->subMonths($i);
            $period     = $month->format('Y-m');
            $monthStart = $month->copy()->startOfMonth()->toDateString();
            $monthEnd   = $month->copy()->endOfMonth()->toDateString();

            $budgets = $user->budgets()->where('period', $period)->get();
            if ($budgets->isEmpty()) {
                break;
            }

            $hasOverbudget = false;
            foreach ($budgets as $budget) {
                $spent = (float) $user->transactions()
                    ->where('type', 'expense')
                    ->where('category', $budget->category)
                    ->whereBetween('transaction_date', [$monthStart, $monthEnd])
                    ->sum('amount');
                if ($spent > (float) $budget->monthly_limit) {
                    $hasOverbudget = true;
                    break;
                }
            }

            if ($hasOverbudget) {
                break;
            }

            $count++;
        }
        return $count;
    }

    public function leaderboard(): array
    {
        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $monthEnd = Carbon::now()->endOfMonth()->toDateString();

        $users = User::query()
            ->select('id', 'name', 'avatar')
            ->withCount([
                'transactions as log_days' => function ($query) use ($monthStart, $monthEnd) {
                    $query->selectRaw('COUNT(DISTINCT transaction_date)')
                        ->whereBetween('transaction_date', [$monthStart, $monthEnd]);
                },
            ])
            ->with([
                'transactions' => fn($query) => $query
                    ->selectRaw('user_id, type, SUM(amount) as total')
                    ->whereBetween('transaction_date', [$monthStart, $monthEnd])
                    ->groupBy('user_id', 'type'),
                'budgets' => fn($query) => $query->where('period', Carbon::now()->format('Y-m')),
            ])
            ->get();

        $rows = $users->map(function (User $user) use ($monthStart, $monthEnd) {
            $income = 0.0;
            $expense = 0.0;

            foreach ($user->transactions as $row) {
                if ($row->type === 'income') {
                    $income = (float) $row->total;
                }

                if ($row->type === 'expense') {
                    $expense = (float) $row->total;
                }
            }

            $savingRate = $income > 0 ? (($income - $expense) / $income) * 100 : 0;
            $logDays = (int) $user->log_days;

            $period = Carbon::now()->format('Y-m');
            $overBudget = 0;
            $badgeUnlocked = 0;

            if ($income > $expense) {
                $badgeUnlocked++;
            }

            if ($user->transactions->count() >= 20) {
                $badgeUnlocked++;
            }

            $score = round(max(0, min(100,
                $savingRate + ($logDays * 2) + ($badgeUnlocked * 3) - ($overBudget * 10)
            )), 2);

            return [
                'name'             => $user->name,
                'avatar'           => $user->avatar,
                'discipline_score' => $score,
                'saving_rate'      => round($savingRate, 2),
                'active_days'      => $logDays,
            ];
        })
            ->sortByDesc('discipline_score')
            ->values();

        return $rows->map(function (array $row, int $index) {
            $row['rank'] = $index + 1;
            return $row;
        })->take(10)->all();
    }

    private function predictionBounds(array $payload): array
    {
        $income = max(0, (float) ($payload['income'] ?? 0));
        $expense = max(0, (float) ($payload['expense'] ?? 0));
        $loanPayment = max(0, (float) ($payload['loan_payment'] ?? 0));

        // More realistic bounds: max is 1.5x net income, min is negative of total obligations
        $netIncome = $income - $expense;
        $maxExpected = max($income * 1.5, abs($netIncome) * 2);
        $minExpected = -1 * ($expense + $loanPayment);

        return [
            'min' => $minExpected,
            'max' => $maxExpected,
        ];
    }

    private function estimateNextMonthBalance(Collection $monthlyChart, array $currentMonthSummary): float
    {
        // Only consider months with actual data (non-zero activity)
        $activeMonths = $monthlyChart->filter(fn($row) => $row['income'] > 0 || $row['expense'] > 0);

        if ($activeMonths->isEmpty()) {
            // No history at all, use current month summary
            return round($currentMonthSummary['balance'], 2);
        }

        // Weighted average favoring recent months
        $values = $activeMonths->pluck('balance')->values()->all();
        $count = count($values);
        $weightedSum = 0;
        $weightTotal = 0;

        for ($i = 0; $i < $count; $i++) {
            $weight = $i + 1;
            $weightedSum += $values[$i] * $weight;
            $weightTotal += $weight;
        }

        return round($weightedSum / $weightTotal, 2);
    }

    private function buildMonthlyChart(User $user, int $months): array
    {
        $startMonth = Carbon::now()->startOfMonth()->subMonths($months - 1);
        $result = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $startMonth->copy()->addMonths($i);
            $monthKey = $month->format('Y-m');
            $monthStart = $month->copy()->startOfMonth()->toDateString();
            $monthEnd = $month->copy()->endOfMonth()->toDateString();

            $income = (float) $user->transactions()
                ->where('type', 'income')
                ->whereBetween('transaction_date', [$monthStart, $monthEnd])
                ->sum('amount');

            $expense = (float) $user->transactions()
                ->where('type', 'expense')
                ->whereBetween('transaction_date', [$monthStart, $monthEnd])
                ->sum('amount');

            $result[] = [
                'month' => $monthKey,
                'income' => round($income, 2),
                'expense' => round($expense, 2),
                'balance' => round($income - $expense, 2),
            ];
        }

        return $result;
    }

    private function determineIncomePattern(Collection $trend): string
    {
        $first = (float) ($trend->first() ?? 0);
        $last = (float) ($trend->last() ?? 0);

        if ($last > $first * 1.1) {
            return 'meningkat';
        }

        if ($last < $first * 0.9) {
            return 'menurun';
        }

        return 'stabil';
    }

    private function hasPositiveStreak(Collection $chart, int $required): bool
    {
        $streak = 0;

        foreach ($chart as $row) {
            if (($row['balance'] ?? 0) > 0) {
                $streak++;
                if ($streak >= $required) {
                    return true;
                }
            } else {
                $streak = 0;
            }
        }

        return false;
    }
}
