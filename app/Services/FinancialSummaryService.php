<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;

/**
 * Builds monthly chart data and shared financial summaries.
 * Used internally by other insight services.
 */
class FinancialSummaryService
{
    /**
     * Build a month-by-month income/expense/balance chart for the user.
     *
     * @return array<int, array{month: string, income: float, expense: float, balance: float}>
     */
    public function buildMonthlyChart(User $user, int $months): array
    {
        $startMonth = Carbon::now()->startOfMonth()->subMonths($months - 1);
        $result = [];

        for ($i = 0; $i < $months; $i++) {
            $month      = $startMonth->copy()->addMonths($i);
            $monthKey   = $month->format('Y-m');
            $monthStart = $month->copy()->startOfMonth()->toDateString();
            $monthEnd   = $month->copy()->endOfMonth()->toDateString();

            $income = (float) $user->transactions()
                ->where('type', 'income')
                ->whereBetween('transaction_date', [$monthStart, $monthEnd])
                ->sum('amount');

            $expense = (float) $user->transactions()
                ->where('type', 'expense')
                ->whereBetween('transaction_date', [$monthStart, $monthEnd])
                ->sum('amount');

            $result[] = [
                'month'   => $monthKey,
                'income'  => round($income, 2),
                'expense' => round($expense, 2),
                'balance' => round($income - $expense, 2),
            ];
        }

        return $result;
    }

    /**
     * Summarise income/expense/balance/saving_rate for the current month.
     *
     * @return array{income: float, expense: float, balance: float, saving_rate: float}
     */
    public function currentMonthSummary(User $user): array
    {
        $start = Carbon::now()->startOfMonth();
        $end   = Carbon::now()->endOfMonth();

        $monthly = $user->transactions()
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $income     = $monthly->where('type', 'income')->sum('amount');
        $expense    = $monthly->where('type', 'expense')->sum('amount');
        $balance    = $income - $expense;
        $savingRate = $income > 0 ? ($balance / $income) * 100 : 0;

        return [
            'income'      => round($income, 2),
            'expense'     => round($expense, 2),
            'balance'     => round($balance, 2),
            'saving_rate' => round($savingRate, 2),
        ];
    }

    /**
     * Determine whether income is trending up, down, or stable.
     */
    public function determineIncomePattern(\Illuminate\Support\Collection $trend): string
    {
        $first = (float) ($trend->first() ?? 0);
        $last  = (float) ($trend->last() ?? 0);

        if ($last > $first * 1.1) {
            return 'meningkat';
        }

        if ($last < $first * 0.9) {
            return 'menurun';
        }

        return 'stabil';
    }
}
