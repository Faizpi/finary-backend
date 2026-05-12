<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinancialInsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly FinancialInsightService $insightService)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->insightService->dashboard($request->user()),
        ]);
    }
}
