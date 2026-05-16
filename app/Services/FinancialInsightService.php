<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Thin orchestrator — delegates to focused services.
 * Kept as the public API consumed by controllers so that route/controller
 * changes are zero for this batch.
 */
class FinancialInsightService
{
    public function __construct(
        private readonly FinancialSummaryService $summaryService,
        private readonly BudgetStatusService $budgetStatusService,
        private readonly BadgeService $badgeService,
        private readonly LeaderboardService $leaderboardService,
        private readonly PredictionService $predictionService,
    ) {
    }

    public function dashboard(User $user): array
    {
        $summary = $this->summaryService->currentMonthSummary($user);

        return [
            'summary'               => $summary,
            'monthly_chart'         => $this->summaryService->buildMonthlyChart($user, 6),
            'recent_transactions'   => $user->transactions()
                ->latest('transaction_date')
                ->latest('id')
                ->limit(5)
                ->get(),
            'budget_snapshot'       => $this->budgetStatusService->budgetStatus($user),
            'latest_classification' => optional($user->assessments()->latest()->first())->classification,
        ];
    }

    public function profile(User $user): array
    {
        $summary        = $this->summaryService->currentMonthSummary($user);
        $expenseRatio   = $summary['income'] > 0 ? $summary['expense'] / $summary['income'] : 1;
        $latestAssessment = $user->assessments()->latest()->first();

        if ($expenseRatio > 0.85) {
            $spendingHabit = 'agresif';
        } elseif ($expenseRatio > 0.65) {
            $spendingHabit = 'moderat';
        } else {
            $spendingHabit = 'terkendali';
        }

        $monthlyChart = collect($this->summaryService->buildMonthlyChart($user, 4));
        $incomeTrend  = $monthlyChart->pluck('income')->values();
        $incomePattern = $this->summaryService->determineIncomePattern($incomeTrend);

        $savingBehavior = 'perlu perbaikan';
        if ($summary['saving_rate'] >= 25) {
            $savingBehavior = 'konsisten';
        } elseif ($summary['saving_rate'] > 0) {
            $savingBehavior = 'berkembang';
        }

        $warnings = [];
        foreach ($this->budgetStatusService->budgetStatus($user) as $budgetRow) {
            if ($budgetRow['is_overbudget']) {
                $warnings[] = 'Kategori ' . $budgetRow['category'] . ' sudah melewati batas budget.';
            }
        }
        if ($summary['balance'] < 0) {
            $warnings[] = 'Cashflow bulan ini negatif. Pangkas pengeluaran non-esensial.';
        }

        $nextMonthPrediction = $this->estimateNextMonthBalance($monthlyChart, $summary);

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

        $prediction = $this->predictionService->dailyPrediction($user, $summary, $latestAssessment, $nextMonthPrediction);

        return [
            'spending_habit'  => $spendingHabit,
            'income_pattern'  => $incomePattern,
            'saving_behavior' => $savingBehavior,
            'warnings'        => $warnings,
            'prediction'      => $prediction,
            'recommendations' => array_values(array_unique(array_merge(
                $recommendations,
                $prediction['recommendations'] ?? [],
            ))),
        ];
    }

    public function budgetStatus(User $user): array
    {
        return $this->budgetStatusService->budgetStatus($user);
    }

    public function badges(User $user): array
    {
        return $this->badgeService->badges($user);
    }

    public function leaderboard(): array
    {
        return $this->leaderboardService->leaderboard();
    }

    // ─── Private helpers kept here for profile() ────────────────────────

    private function estimateNextMonthBalance(Collection $monthlyChart, array $currentMonthSummary): float
    {
        $activeMonths = $monthlyChart->filter(fn($row) => $row['income'] > 0 || $row['expense'] > 0);

        if ($activeMonths->isEmpty()) {
            return round($currentMonthSummary['balance'], 2);
        }

        $values      = $activeMonths->pluck('balance')->values()->all();
        $count       = count($values);
        $weightedSum = 0;
        $weightTotal = 0;

        for ($i = 0; $i < $count; $i++) {
            $weight       = $i + 1;
            $weightedSum += $values[$i] * $weight;
            $weightTotal += $weight;
        }

        return round($weightedSum / $weightTotal, 2);
    }
}
