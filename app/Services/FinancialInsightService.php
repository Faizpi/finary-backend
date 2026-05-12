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

        $nextMonthPrediction = round($monthlyChart->pluck('balance')->avg() ?? 0, 2);

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

        return Cache::remember($cacheKey, Carbon::now()->endOfDay(), function () use ($monthly, $latestAssessment, $fallbackBalance, $dateKey) {
            $payload = [
                'income' => (float) ($monthly['income'] > 0 ? $monthly['income'] : ($latestAssessment?->monthly_income ?? 0)),
                'expense' => (float) ($monthly['expense'] > 0 ? $monthly['expense'] : ($latestAssessment?->monthly_expense ?? 0)),
                'savings' => (float) ($latestAssessment?->actual_savings ?? max($monthly['balance'], 0)),
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

                return [
                    'next_month_balance' => $adjustedBalance,
                    'warning_probability' => (float) $mlResult['warning_probability'],
                    'warning_flag' => (int) $mlResult['warning_flag'],
                    'recommendations' => array_values($mlResult['recommendations'] ?? []),
                    'currency' => 'IDR',
                    'source' => 'ml',
                    'generated_for' => $dateKey,
                    'payload' => $payload,
                ];
            }

            $expenseRatio = $payload['income'] > 0 ? $payload['expense'] / $payload['income'] : 1;
            $warningProbability = min(0.95, max(0.05, ($expenseRatio * 0.55) + ($payload['loan_payment'] > 0 ? 0.15 : 0)));

            return [
                'next_month_balance' => $fallbackBalance,
                'warning_probability' => round($warningProbability, 4),
                'warning_flag' => $warningProbability >= 0.65 || $fallbackBalance < 0 ? 1 : 0,
                'recommendations' => [
                    $fallbackBalance < 0
                    ? 'Prioritaskan pemangkasan pengeluaran variabel sebelum akhir bulan.'
                    : 'Review saldo prediksi harian dan jaga transaksi besar tetap terencana.',
                ],
                'currency' => 'IDR',
                'source' => 'rule-based',
                'generated_for' => $dateKey,
                'payload' => $payload,
            ];
        });
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
        $savings = max(0, (float) ($payload['savings'] ?? 0));
        $emergencyFund = max(0, (float) ($payload['emergency_fund'] ?? 0));
        $loanPayment = max(0, (float) ($payload['loan_payment'] ?? 0));

        $maxExpected = max($income * 3, $income + $savings + $emergencyFund);
        $minExpected = -1 * max($expense * 2, $expense + $loanPayment);

        return [
            'min' => $minExpected,
            'max' => $maxExpected,
        ];
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
