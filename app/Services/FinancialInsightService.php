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

                return [
                    'next_month_balance' => round($adjustedBalance, 2),
                    'warning_probability' => round($mlWarningProb, 4),
                    'warning_flag' => (int) $mlResult['warning_flag'],
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

            return [
                'next_month_balance' => $predictedBalance,
                'warning_probability' => $warningProbability,
                'warning_flag' => ($warningProbability >= 0.55 || $predictedBalance < 0) ? 1 : 0,
                'recommendations' => $this->buildPredictionRecommendations($expenseRatio, $predictedBalance, $loanBurden, $savingsBuffer),
                'currency' => 'IDR',
                'source' => 'rule-based',
                'generated_for' => $dateKey,
                'payload' => $payload,
            ];
        });
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

        $monthsPositive = $chart->where('balance', '>', 0)->count();
        $hasDeficit = $chart->where('balance', '<', 0)->isNotEmpty();
        $latestPositive = ($chart->last()['balance'] ?? 0) > 0;

        $distinctDaysThisMonth = $transactions
            ->whereBetween('transaction_date', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
            ->pluck('transaction_date')
            ->map(fn($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->count();

        $unlocked = [
            [
                'key' => 'first_saver',
                'name' => 'First Saver',
                'description' => 'Bulan positif pertama berhasil dicapai.',
                'unlocked' => $monthsPositive >= 1,
            ],
            [
                'key' => 'saving_streak',
                'name' => 'Saving Streak',
                'description' => '3 bulan beruntun saldo akhir positif.',
                'unlocked' => $this->hasPositiveStreak($chart, 3),
            ],
            [
                'key' => 'budget_keeper',
                'name' => 'Budget Keeper',
                'description' => 'Tidak ada kategori overbudget bulan ini.',
                'unlocked' => $budgetRows->isNotEmpty() && $budgetRows->where('is_overbudget', true)->isEmpty(),
            ],
            [
                'key' => 'expense_tracker',
                'name' => 'Expense Tracker',
                'description' => 'Minimal 20 transaksi sudah tercatat.',
                'unlocked' => $transactions->count() >= 20,
            ],
            [
                'key' => 'side_hustler',
                'name' => 'Side Hustler',
                'description' => 'Sudah punya pemasukan kategori side hustle/freelance.',
                'unlocked' => $transactions
                    ->where('type', 'income')
                    ->filter(fn(Transaction $t) => str_contains(strtolower($t->category), 'side') || str_contains(strtolower($t->category), 'free'))
                    ->isNotEmpty(),
            ],
            [
                'key' => 'daily_logger',
                'name' => 'Daily Logger',
                'description' => 'Aktif mencatat setidaknya 10 hari dalam bulan ini.',
                'unlocked' => $distinctDaysThisMonth >= 10,
            ],
            [
                'key' => 'comeback',
                'name' => 'Comeback',
                'description' => 'Pernah defisit lalu berhasil kembali surplus.',
                'unlocked' => $hasDeficit && $latestPositive,
            ],
        ];

        return [
            'summary' => [
                'unlocked_count' => collect($unlocked)->where('unlocked', true)->count(),
                'total_badges' => count($unlocked),
            ],
            'badges' => $unlocked,
        ];
    }

    public function leaderboard(): array
    {
        $users = User::query()
            ->with(['transactions', 'budgets'])
            ->get();

        $rows = $users->map(function (User $user) {
            $chart = collect($this->buildMonthlyChart($user, 3));
            $latest = $chart->last() ?? ['income' => 0, 'expense' => 0, 'balance' => 0];
            $savingRate = $latest['income'] > 0 ? (($latest['income'] - $latest['expense']) / $latest['income']) * 100 : 0;
            $logDays = $user->transactions()
                ->whereBetween('transaction_date', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
                ->distinct('transaction_date')
                ->count('transaction_date');

            $overBudgetCount = collect($this->budgetStatus($user))->where('is_overbudget', true)->count();
            $badgeUnlocked = $this->badges($user)['summary']['unlocked_count'];

            $score = round(max(0, min(100, $savingRate + ($logDays * 2) + ($badgeUnlocked * 3) - ($overBudgetCount * 10))), 2);

            return [
                'name' => $user->name,
                'discipline_score' => $score,
                'saving_rate' => round($savingRate, 2),
                'active_days' => $logDays,
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
