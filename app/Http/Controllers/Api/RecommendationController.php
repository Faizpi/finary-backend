<?php

namespace App\Http\Controllers\Api;

use App\Contracts\InterestCategoryInferrerContract;
use App\Contracts\SideHustleRecommenderContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recommendation\SideHustleRequest;
use Illuminate\Http\JsonResponse;

class RecommendationController extends Controller
{
    public function __construct(
        private readonly SideHustleRecommenderContract $recommender,
        private readonly InterestCategoryInferrerContract $interestInferrer,
    ) {
    }

    public function sideHustles(SideHustleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $latestAssessment = $request->user()->assessments()->latest()->first();
        $sideHustleContext = $latestAssessment?->metadata['side_hustle_context'] ?? [];

        $skills = $validated['skills']
            ?? ($latestAssessment?->skills ?? $sideHustleContext['skills'] ?? []);

        $payload = [
            'experience_level' => $validated['experience_level']
                ?? ($sideHustleContext['experience_level'] ?? 'Beginner'),
            'interest_category' => $validated['interest_category']
                ?? ($sideHustleContext['interest_category'] ?? $this->interestInferrer->infer($skills)),
            'available_hours_per_week' => $validated['available_hours_per_week']
                ?? (int) ($latestAssessment?->available_hours_per_week
                    ?? $sideHustleContext['available_hours_per_week']
                    ?? 10),
            'skills'         => $skills,
            'classification' => $validated['classification'] ?? ($latestAssessment?->classification ?? 'stable'),
        ];

        return response()->json([
            'data' => $this->recommender->recommend($payload),
        ]);
    }
}
