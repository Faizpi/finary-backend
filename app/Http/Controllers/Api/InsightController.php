<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinancialInsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsightController extends Controller
{
    public function __construct(private readonly FinancialInsightService $insightService)
    {
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->insightService->profile($request->user()),
        ]);
    }

    public function badges(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->insightService->badges($request->user()),
        ]);
    }

    public function leaderboard(): JsonResponse
    {
        return response()->json([
            'data' => $this->insightService->leaderboard(),
        ]);
    }
}
