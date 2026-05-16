<?php

namespace App\Services;

use App\Contracts\MlGatewayContract;
use App\Contracts\PredictionCacheContract;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Daily financial prediction — ML-first with rule-based fallback.
 */
class PredictionService
{
    public function __construct(
        private readonly MlGatewayContract $mlGateway,
        private readonly PredictionCacheContract $predictionCache,
        private readonly FinancialSummaryService $summaryService,
    ) {
    }

    /**
     * Build a daily prediction for the user, cached until end of day.
     *
     * @return array<string, mixed>
     */
    public function dailyPrediction(User $user, array $monthly, mixed $latestAssessment, float $fallbackBalance): array
    {
        $dateKey = Carbon::now()->toDateString();

        return $this->predictionCache->rememberDaily($user->id, function () use ($user, $monthly, $latestAssessment, $fallbackBalance, $dateKey) {
            $assessmentIncome  = (float) ($latestAssessment?->monthly_income ?? 0);
            $assessmentExpense = (float) ($latestAssessment?->monthly_expense ?? 0);
            $actualIncome      = (float) ($monthly['income'] ?? 0);
            $actualExpense     = (float) ($monthly['expense'] ?? 0);

            $now           = Carbon::now();
            $daysElapsed   = max(1, $now->day);
            $daysInMonth   = max(1, $now->daysInMonth);
            $monthProgress = min(1.0, max(0.05, $daysElapsed / $daysInMonth));

            $projectedIncomeFromTransactions  = $actualIncome > 0 ? $actualIncome / $monthProgress : 0;
            $projectedExpenseFromTransactions = $actualExpense > 0 ? $actualExpense / $monthProgress : 0;

            $income  = max($assessmentIncome, $actualIncome, min($projectedIncomeFromTransactions, $assessmentIncome * 1.3));
            $expense = max($assessmentExpense, $actualExpense, $projectedExpenseFromTransactions);

            if ($assessmentIncome <= 0) {
                $income = max($actualIncome, $projectedIncomeFromTransactions);
            }
            if ($assessmentExpense <= 0) {
                $expense = max($actualExpense, $projectedExpenseFromTransactions);
            }

            $actualBalance = $income - $expense;
            $savings       = (float) ($latestAssessment?->actual_savings ?? max($actualBalance, 0));

            $payload = [
                'income'          => $income,
                'expense'         => $expense,
                'savings'         => $savings,
                'target_tabungan' => (float) ($latestAssessment?->budget_goal ?? 0),
                'loan_payment'    => (float) ($latestAssessment?->loan_payment ?? 0),
                'emergency_fund'  => (float) ($latestAssessment?->emergency_fund ?? 0),
            ];

            $mlResult = $this->mlGateway->predictInsight($payload);

            if (
                is_array($mlResult)
                && array_key_exists('predicted_next_month_balance', $mlResult)
                && array_key_exists('warning_probability', $mlResult)
                && array_key_exists('warning_flag', $mlResult)
            ) {
                $predictedBalance = (float) $mlResult['predicted_next_month_balance'];
                $bounds           = $this->predictionBounds($payload);
                $adjustedBalance  = min(max($predictedBalance, $bounds['min']), $bounds['max']);

                if ($adjustedBalance !== $predictedBalance) {
                    Log::warning('ML prediction out of expected range; clamping', [
                        'predicted' => $predictedBalance,
                        'adjusted'  => $adjustedBalance,
                        'min'       => $bounds['min'],
                        'max'       => $bounds['max'],
                        'payload'   => $payload,
                    ]);
                }

                $mlWarningProb = min(1.0, max(0.0, (float) $mlResult['warning_probability']));
                $warningFlag   = (int) $mlResult['warning_flag'];

                return [
                    'next_month_balance'  => round($adjustedBalance, 2),
                    'warning_probability' => round($mlWarningProb, 4),
                    'warning_flag'        => $warningFlag,
                    'warning_text'        => $this->buildWarningText($warningFlag, $mlWarningProb, $adjustedBalance),
                    'recommendations'     => array_values($mlResult['recommendations'] ?? []),
                    'currency'            => 'IDR',
                    'source'              => 'ml',
                    'generated_for'       => $dateKey,
                    'payload'             => $payload,
                ];
            }

            // --- Rule-based fallback ---
            $expenseRatio = $income > 0 ? $expense / $income : 0.5;
            $loanBurden   = ($income > 0 && $payload['loan_payment'] > 0) ? $payload['loan_payment'] / $income : 0;

            $warningProbability = 0.0;
            $warningProbability += min(0.45, $expenseRatio * 0.45);
            $warningProbability += min(0.20, $loanBurden * 0.60);

            $savingsBuffer = ($income > 0 && $savings > 0) ? min(0.15, ($savings / $income) * 0.15) : 0;
            $warningProbability -= $savingsBuffer;

            $emergencyBuffer = ($expense > 0 && $payload['emergency_fund'] >= $expense * 3) ? 0.10 : 0;
            $warningProbability -= $emergencyBuffer;
            $warningProbability = round(min(0.95, max(0.05, $warningProbability)), 4);

            $monthlyChart   = collect($this->summaryService->buildMonthlyChart($user, 3));
            $nonZeroBalances = $monthlyChart->pluck('balance')->filter(fn($b) => $b != 0);

            if ($nonZeroBalances->isNotEmpty()) {
                $values     = $nonZeroBalances->values()->all();
                $count      = count($values);
                $weights    = [];
                for ($i = 0; $i < $count; $i++) {
                    $weights[] = $i + 1;
                }
                $weightedSum = 0;
                $weightTotal = array_sum($weights);
                for ($i = 0; $i < $count; $i++) {
                    $weightedSum += $values[$i] * $weights[$i];
                }
                $predictedBalance = round($weightedSum / $weightTotal, 2);
            } else {
                $predictedBalance = round($income - $expense, 2);
            }

            $warningFlag = ($warningProbability >= 0.55 || $predictedBalance < 0) ? 1 : 0;

            return [
                'next_month_balance'  => $predictedBalance,
                'warning_probability' => $warningProbability,
                'warning_flag'        => $warningFlag,
                'warning_text'        => $this->buildWarningText($warningFlag, $warningProbability, $predictedBalance),
                'recommendations'     => $this->buildPredictionRecommendations($expenseRatio, $predictedBalance, $loanBurden, $savingsBuffer),
                'currency'            => 'IDR',
                'source'              => 'rule-based',
                'generated_for'       => $dateKey,
                'payload'             => $payload,
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

    private function predictionBounds(array $payload): array
    {
        $income      = max(0, (float) ($payload['income'] ?? 0));
        $expense     = max(0, (float) ($payload['expense'] ?? 0));
        $loanPayment = max(0, (float) ($payload['loan_payment'] ?? 0));

        $netIncome   = $income - $expense;
        $maxExpected = max($income * 1.5, abs($netIncome) * 2);
        $minExpected = -1 * ($expense + $loanPayment);

        return [
            'min' => $minExpected,
            'max' => $maxExpected,
        ];
    }
}
