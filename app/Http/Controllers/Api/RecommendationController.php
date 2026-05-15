<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recommendation\SideHustleRequest;
use App\Services\SideHustleRecommendationService;
use Illuminate\Http\JsonResponse;

class RecommendationController extends Controller
{
    public function __construct(private readonly SideHustleRecommendationService $recommendationService)
    {
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
                ?? ($sideHustleContext['interest_category'] ?? $this->inferInterestCategory($skills)),
            'available_hours_per_week' => $validated['available_hours_per_week']
                ?? (int) ($latestAssessment?->available_hours_per_week
                    ?? $sideHustleContext['available_hours_per_week']
                    ?? 10),
            'skills'         => $skills,
            'classification' => $validated['classification'] ?? ($latestAssessment?->classification ?? 'stable'),
        ];

        return response()->json([
            'data' => $this->recommendationService->recommend($payload),
        ]);
    }

    private function inferInterestCategory(array $skills): string
    {
        $skillText = strtolower(implode(' ', $skills));

        return match (true) {
            str_contains($skillText, 'design')                                    => 'Graphic Design',
            str_contains($skillText, 'seo') || str_contains($skillText, 'writing')=> 'SEO',
            str_contains($skillText, 'teach')                                     => 'Teaching / Tutoring',
            str_contains($skillText, 'social')                                    => 'Social Media Management',
            default                                                               => 'App Development',
        };
    }
}
