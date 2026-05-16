<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;

/**
 * Computes the monthly leaderboard across all users.
 */
class LeaderboardService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function leaderboard(): array
    {
        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $monthEnd   = Carbon::now()->endOfMonth()->toDateString();

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

        $rows = $users->map(function (User $user) {
            $income  = 0.0;
            $expense = 0.0;

            foreach ($user->transactions as $row) {
                if ($row->type === 'income') {
                    $income = (float) $row->total;
                }
                if ($row->type === 'expense') {
                    $expense = (float) $row->total;
                }
            }

            $savingRate   = $income > 0 ? (($income - $expense) / $income) * 100 : 0;
            $logDays      = (int) $user->log_days;
            $overBudget   = 0;
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
}
