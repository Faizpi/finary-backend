<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assessment\StoreAssessmentRequest;
use App\Http\Resources\AssessmentResource;
use App\Services\FinancialClassifierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
    public function __construct(private readonly FinancialClassifierService $classifier)
    {
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
            'monthly_income'       => $validated['monthly_income'],
            'monthly_expense_total'=> $validated['monthly_expense'],
            'actual_savings'       => $validated['actual_savings'],
            'budget_goal'          => $validated['budget_goal'],
            'emergency_fund'       => $validated['emergency_fund'],
        ]);

        $assessment = $request->user()->assessments()->create([
            'monthly_income'  => $validated['monthly_income'],
            'monthly_expense' => $validated['monthly_expense'],
            'actual_savings'  => $validated['actual_savings'],
            'budget_goal'     => $validated['budget_goal'],
            'emergency_fund'  => $validated['emergency_fund'],
            'loan_payment'    => $validated['loan_payment'] ?? 0,
            'classification'  => $classificationResult['classification'] ?? ($validated['classification'] ?? 'unknown'),
            'ml_score'        => $classificationResult['score'] ?? ($validated['ml_score'] ?? null),
            'ml_explanation'  => $classificationResult['explanation'] ?? ($validated['ml_explanation'] ?? null),
            'metadata'        => [
                'source'                => $classificationResult['source'] ?? 'unknown',
                'classification_result' => $classificationResult,
            ],
        ]);

        return response()->json([
            'message' => 'Assessment tersimpan.',
            'data'    => new AssessmentResource($assessment),
        ], 201);
    }
}
