<?php

namespace App\Services;

class FinancialClassifierService
{
    public function __construct(private readonly MlGatewayService $mlGateway)
    {
    }

    public function classify(array $payload): array
    {
        $normalizedPayload = $this->normalizePayload($payload);
        $mlResult = $this->mlGateway->classifyAssessment($normalizedPayload);

        if (
            is_array($mlResult)
            && isset($mlResult['classification'])
            && in_array($mlResult['classification'], ['survival', 'stable', 'growth'], true)
        ) {
            return [
                'classification' => $mlResult['classification'],
                'score' => (float) ($mlResult['score'] ?? 0),
                'saving_rate' => (float) ($mlResult['financial_indicators']['savings_rate'] ?? 0),
                'source' => 'ml',
                'probabilities' => $mlResult['probabilities'] ?? [],
                'financial_indicators' => $mlResult['financial_indicators'] ?? [],
                'risk_flags' => $mlResult['risk_flags'] ?? [],
                'recommendation_focus' => $mlResult['recommendation_focus'] ?? [],
                'explanation' => $mlResult['explanation'] ?? null,
            ];
        }

        return $this->classifyByRules($normalizedPayload);
    }

    private function normalizePayload(array $payload): array
    {
        return [
            'monthly_income' => (float) ($payload['monthly_income'] ?? 0),
            'monthly_expense_total' => (float) ($payload['monthly_expense_total'] ?? $payload['monthly_expense'] ?? 0),
            'actual_savings' => (float) ($payload['actual_savings'] ?? 0),
            'budget_goal' => (float) ($payload['budget_goal'] ?? 0),
            'emergency_fund' => (float) ($payload['emergency_fund'] ?? 0),
        ];
    }

    private function classifyByRules(array $payload): array
    {
        $income = (float) ($payload['monthly_income'] ?? 0);
        $expense = (float) ($payload['monthly_expense_total'] ?? 0);
        $actualSavings = (float) ($payload['actual_savings'] ?? 0);
        $budgetGoal = (float) ($payload['budget_goal'] ?? 0);
        $emergencyFund = (float) ($payload['emergency_fund'] ?? 0);

        $netCashFlow = $income - $expense;
        $expenseRatio = $income > 0 ? $expense / $income : 1;
        $savingRate = $income > 0 ? $actualSavings / $income : 0;
        $spendingEfficiency = $budgetGoal > 0 ? min(1, $actualSavings / $budgetGoal) : 0;

        $riskFlags = [
            'negative_cash_flow' => $netCashFlow < 0,
            'high_expense_ratio' => $expenseRatio >= 0.85,
            'low_savings_rate' => $savingRate < 0.1,
            'savings_goal_not_met' => $budgetGoal > 0 && $actualSavings < $budgetGoal,
            'low_spending_efficiency' => $spendingEfficiency < 0.5,
        ];

        if ($netCashFlow <= 0 || $expenseRatio >= 0.9 || $savingRate < 0.05) {
            $classification = 'survival';
            $score = 0.72;
            $focus = [
                'prioritize_essential_expenses',
                'stabilize_monthly_cash_flow',
                'find_short_term_income_support',
            ];
        } elseif ($savingRate >= 0.2 && $actualSavings >= $budgetGoal && $emergencyFund >= max($expense, 1)) {
            $classification = 'growth';
            $score = 0.78;
            $focus = [
                'maintain_growth_momentum',
                'increase_investment_allocation',
                'optimize_long_term_savings',
            ];
        } else {
            $classification = 'stable';
            $score = 0.68;
            $focus = [
                'keep_expense_ratio_controlled',
                'build_budgeting_consistency',
                'grow_emergency_fund',
            ];
        }

        $probabilities = [
            'survival' => $classification === 'survival' ? $score : round((1 - $score) / 2, 4),
            'stable' => $classification === 'stable' ? $score : round((1 - $score) / 2, 4),
            'growth' => $classification === 'growth' ? $score : round((1 - $score) / 2, 4),
        ];

        return [
            'classification' => $classification,
            'score' => $score,
            'saving_rate' => round($savingRate, 4),
            'source' => 'rule-based',
            'probabilities' => $probabilities,
            'financial_indicators' => [
                'monthly_income' => $income,
                'monthly_expense_total' => $expense,
                'actual_savings' => $actualSavings,
                'budget_goal' => $budgetGoal,
                'emergency_fund' => $emergencyFund,
                'net_cash_flow' => $netCashFlow,
                'expense_ratio' => round($expenseRatio, 4),
                'savings_rate' => round($savingRate, 4),
                'spending_efficiency' => round($spendingEfficiency, 4),
            ],
            'risk_flags' => $riskFlags,
            'recommendation_focus' => $focus,
            'explanation' => 'Fallback rule-based classification was used because the ML service was unavailable.',
        ];
    }
}
