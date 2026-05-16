<?php

namespace App\Services;

use App\Contracts\FinancialClassifierContract;
use App\Contracts\UserRegistrationContract;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserRegistrationService implements UserRegistrationContract
{
    /**
     * Default onboarding assessment values for a brand-new user.
     * These represent a typical Indonesian urban salary earner.
     */
    private const DEFAULT_ASSESSMENT = [
        'monthly_income'            => 6000000,
        'monthly_expense'           => 4200000,
        'monthly_expense_total'     => 4200000,
        'actual_savings'            => 1800000,
        'budget_goal'               => 1200000,
        'emergency_fund'            => 5000000,
        'loan_payment'              => 0,
        'available_hours_per_week'  => 8,
        'skills'                    => ['communication'],
    ];

    public function __construct(
        private readonly FinancialClassifierContract $classifier,
    ) {}

    public function register(array $validated): array
    {
        return DB::transaction(function () use ($validated): array {
            $user = User::create($validated);

            $classificationResult = $this->classifier->classify(self::DEFAULT_ASSESSMENT);

            $user->assessments()->create([
                ...self::DEFAULT_ASSESSMENT,
                'classification' => $classificationResult['classification'],
                'ml_score'       => $classificationResult['score'] ?? null,
                'ml_explanation' => $classificationResult['explanation'] ?? null,
                'metadata'       => [
                    'source'                => $classificationResult['source'],
                    'classification_result' => $classificationResult,
                    'stage'                 => 'onboarding',
                ],
            ]);

            $token = $user->createToken('finary-token')->plainTextToken;

            return ['user' => $user, 'token' => $token];
        });
    }
}
