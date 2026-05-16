<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\User;
use Carbon\Carbon;

/**
 * Computes budget status for the current period.
 */
class BudgetStatusService
{
    /**
     * Return spend/remaining/progress for every budget pocket of the user
     * in the current month.
     *
     * @return array<int, array<string, mixed>>
     */
    public function budgetStatus(User $user): array
    {
        $period  = Carbon::now()->format('Y-m');
        $budgets = $user->budgets()->where('period', $period)->get();

        return $budgets->map(function (Budget $budget) use ($user, $period) {
            $periodDate = Carbon::createFromFormat('Y-m', $period);
            $spent      = (float) $user->transactions()
                ->where('type', 'expense')
                ->where('category', $budget->category)
                ->whereBetween('transaction_date', [
                    $periodDate->copy()->startOfMonth()->toDateString(),
                    $periodDate->copy()->endOfMonth()->toDateString(),
                ])
                ->sum('amount');

            $remaining = max(0, $budget->monthly_limit - $spent);
            $progress  = $budget->monthly_limit > 0
                ? ($spent / $budget->monthly_limit) * 100
                : 0;

            return [
                'id'               => $budget->id,
                'category'         => $budget->category,
                'period'           => $budget->period,
                'monthly_limit'    => (float) $budget->monthly_limit,
                'spent'            => round($spent, 2),
                'remaining'        => round($remaining, 2),
                'progress_percent' => round($progress, 2),
                'is_overbudget'    => $spent > $budget->monthly_limit,
            ];
        })->values()->all();
    }

    /**
     * Count consecutive months (up to $months back) where the user had
     * budgets and none were overbudget. Stops at the first overbudget month.
     */
    public function countBudgetKeeperMonths(User $user, int $months): int
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
}
