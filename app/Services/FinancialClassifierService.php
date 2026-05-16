<?php

namespace App\Services;

use App\Contracts\FinancialClassifierContract;
use App\Contracts\MlGatewayContract;

class FinancialClassifierService implements FinancialClassifierContract
{
    public function __construct(private readonly MlGatewayContract $mlGateway)
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

        // Saving rate: use the better of actual savings or net cashflow relative to income
        // This prevents misclassification when user reports low "actual_savings" but has good cashflow
        $savingRateFromCashflow = $income > 0 ? $netCashFlow / $income : 0;
        $savingRateFromActual = $income > 0 ? $actualSavings / $income : 0;
        $savingRate = max($savingRateFromCashflow, $savingRateFromActual);

        $spendingEfficiency = $budgetGoal > 0 ? min(1, max($actualSavings, $netCashFlow) / $budgetGoal) : 0;

        $riskFlags = [
            'negative_cash_flow' => $netCashFlow < 0,
            'high_expense_ratio' => $expenseRatio >= 0.85,
            'low_savings_rate' => $savingRate < 0.1,
            'savings_goal_not_met' => $budgetGoal > 0 && max($actualSavings, $netCashFlow) < $budgetGoal,
            'low_spending_efficiency' => $spendingEfficiency < 0.5,
        ];

        if ($netCashFlow <= 0 || $expenseRatio >= 0.9 || $savingRate < 0.05) {
            $classification = 'survival';
            // Score reflects how severe: closer to 1.0 = more confident
            $severityFactors = ($netCashFlow <= 0 ? 1 : 0) + ($expenseRatio >= 0.9 ? 1 : 0) + ($savingRate < 0.05 ? 1 : 0);
            $score = round(0.55 + ($severityFactors * 0.12), 4);
            $focus = [
                'prioritize_essential_expenses',
                'stabilize_monthly_cash_flow',
                'find_short_term_income_support',
            ];
        } elseif ($savingRate >= 0.2 && max($actualSavings, $netCashFlow) >= $budgetGoal && $emergencyFund >= $expense) {
            $classification = 'growth';
            $growthFactors = ($savingRate >= 0.3 ? 1 : 0) + ($emergencyFund >= $expense * 3 ? 1 : 0) + ($spendingEfficiency >= 0.8 ? 1 : 0);
            $score = round(0.65 + ($growthFactors * 0.08), 4);
            $focus = [
                'maintain_growth_momentum',
                'increase_investment_allocation',
                'optimize_long_term_savings',
            ];
        } else {
            $classification = 'stable';
            $score = round(0.55 + (min($savingRate, 0.2) * 1.5), 4);
            $focus = [
                'keep_expense_ratio_controlled',
                'build_budgeting_consistency',
                'grow_emergency_fund',
            ];
        }

        // Calculate realistic probabilities based on financial indicators
        $probabilities = $this->calculateProbabilities($classification, $score, $expenseRatio, $savingRate);

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

    private function calculateProbabilities(string $classification, float $score, float $expenseRatio, float $savingRate): array
    {
        // Distribute remaining probability realistically
        $remaining = 1.0 - $score;

        if ($classification === 'survival') {
            // If survival, stable is more likely than growth
            return [
                'survival' => round($score, 4),
                'stable' => round($remaining * 0.7, 4),
                'growth' => round($remaining * 0.3, 4),
            ];
        }

        if ($classification === 'growth') {
            // If growth, stable is more likely than survival
            return [
                'survival' => round($remaining * 0.2, 4),
                'stable' => round($remaining * 0.8, 4),
                'growth' => round($score, 4),
            ];
        }

        // Stable: distribute based on which direction they lean
        $leanGrowth = $savingRate >= 0.15 && $expenseRatio < 0.7;
        return [
            'survival' => round($remaining * ($leanGrowth ? 0.2 : 0.5), 4),
            'stable' => round($score, 4),
            'growth' => round($remaining * ($leanGrowth ? 0.8 : 0.5), 4),
        ];
    }
}
