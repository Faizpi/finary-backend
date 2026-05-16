<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\StoreBudgetRequest;
use App\Http\Requests\Budget\UpdateBudgetRequest;
use App\Models\Budget;
use App\Services\FinancialInsightService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    public function __construct(private readonly FinancialInsightService $insightService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->insightService->budgetStatus($request->user()),
        ]);
    }

    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $budget = $request->user()->budgets()->updateOrCreate(
            [
                'category' => $validated['category'],
                'period'   => $validated['period'] ?? Carbon::now()->format('Y-m'),
            ],
            [
                'monthly_limit' => $validated['monthly_limit'],
            ]
        );

        return response()->json([
            'message' => 'Budget tersimpan.',
            'data'    => $budget,
        ], 201);
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): JsonResponse
    {
        $this->authorize('update', $budget);
        $budget->update($request->validated());

        return response()->json([
            'message' => 'Budget diperbarui.',
            'data'    => $budget->fresh(),
        ]);
    }

    public function destroy(Request $request, Budget $budget): JsonResponse
    {
        $this->authorize('delete', $budget);
        $budget->delete();

        return response()->json([
            'message' => 'Budget dihapus.',
        ]);
    }
}
