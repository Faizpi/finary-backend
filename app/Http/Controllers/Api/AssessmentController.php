<?php

namespace App\Http\Controllers\Api;

use App\Contracts\FinancialClassifierContract;
use App\Contracts\PredictionCacheContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assessment\PatchAssessmentRequest;
use App\Http\Requests\Assessment\StoreAssessmentRequest;
use App\Http\Resources\AssessmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
    public function __construct(
        private readonly FinancialClassifierContract $classifier,
        private readonly PredictionCacheContract $predictionCache,
    ) {
    }

    public function latest(Request $request): JsonResponse
    {
        $assessment = $request->user()->assessments()->latest()->first();

        return response()->json([
            'data' => $assessment ? new AssessmentResource($assessment) : null,
        ]);
    }

    public function store(StoreAssessmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $classificationResult = $this->classifier->classify([
            'monthly_income'        => $validated['monthly_income'],
            'monthly_expense_total' => $validated['monthly_expense'],
            'actual_savings'        => $validated['actual_savings'],
            'budget_goal'           => $validated['budget_goal'],
            'emergency_fund'        => $validated['emergency_fund'],
        ]);

        $assessment = $request->user()->assessments()->create([
            'monthly_income'           => $validated['monthly_income'],
            'monthly_expense'          => $validated['monthly_expense'],
            'actual_savings'           => $validated['actual_savings'],
            'budget_goal'              => $validated['budget_goal'],
            'emergency_fund'           => $validated['emergency_fund'],
            'loan_payment'             => $validated['loan_payment'] ?? 0,
            'available_hours_per_week' => $validated['available_hours_per_week'] ?? 10,
            'skills'                   => $validated['skills'] ?? [],
            'classification'           => $classificationResult['classification'] ?? ($validated['classification'] ?? 'unknown'),
            'ml_score'                 => $classificationResult['score'] ?? ($validated['ml_score'] ?? null),
            'ml_explanation'           => $classificationResult['explanation'] ?? ($validated['ml_explanation'] ?? null),
            'metadata'                 => [
                'source'                => $classificationResult['source'] ?? 'unknown',
                'classification_result' => $classificationResult,
                'side_hustle_context'   => [
                    'experience_level'         => $validated['experience_level'] ?? 'Beginner',
                    'interest_category'        => $validated['interest_category'] ?? null,
                    'available_hours_per_week' => $validated['available_hours_per_week'] ?? 10,
                    'skills'                   => $validated['skills'] ?? [],
                ],
            ],
        ]);

        $this->predictionCache->forgetDaily($request->user()->id);

        return response()->json([
            'message' => 'Assessment tersimpan.',
            'data'    => new AssessmentResource($assessment),
        ], 201);
    }

    public function patchLatest(PatchAssessmentRequest $request): JsonResponse
    {
        $assessment = $request->user()->assessments()->latest()->first();

        if (!$assessment) {
            return response()->json(['message' => 'Belum ada assessment.'], 404);
        }

        $fillable = array_filter($request->validated(), fn($value) => $value !== null);

        if (!empty($fillable)) {
            $assessment->update($fillable);
        }

        $this->predictionCache->forgetDaily($request->user()->id);

        return response()->json([
            'message' => 'Assessment diperbarui.',
            'data'    => new AssessmentResource($assessment->fresh()),
        ]);
    }
}
