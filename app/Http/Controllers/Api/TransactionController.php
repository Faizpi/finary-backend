<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Http\Requests\Transaction\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->transactions()->orderByDesc('transaction_date')->orderByDesc('id');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('month')) {
            [$start, $end] = $this->monthRange((string) $request->query('month'));
            $query->whereBetween('transaction_date', [$start, $end]);
        }

        $perPage = min((int) $request->query('per_page', 30), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => TransactionResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'has_more'     => $paginated->hasMorePages(),
            ],
        ]);
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $transaction = $request->user()->transactions()->create($request->validated());

        $this->invalidatePredictionCache($request->user()->id);

        return response()->json([
            'message' => 'Transaksi berhasil ditambahkan.',
            'data'    => new TransactionResource($transaction),
        ], 201);
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeOwnership($request, $transaction);
        $transaction->update($request->validated());

        $this->invalidatePredictionCache($request->user()->id);

        return response()->json([
            'message' => 'Transaksi berhasil diperbarui.',
            'data'    => new TransactionResource($transaction->fresh()),
        ]);
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeOwnership($request, $transaction);
        $transaction->delete();

        $this->invalidatePredictionCache($request->user()->id);

        return response()->json([
            'message' => 'Transaksi dihapus.',
        ]);
    }

    private function authorizeOwnership(Request $request, Transaction $transaction): void
    {
        abort_if($transaction->user_id !== $request->user()->id, 403, 'Akses ditolak.');
    }

    private function monthRange(string $month): array
    {
        $date = Carbon::createFromFormat('Y-m', $month);

        return [
            $date->copy()->startOfMonth()->toDateString(),
            $date->copy()->endOfMonth()->toDateString(),
        ];
    }

    private function invalidatePredictionCache(int $userId): void
    {
        $dateKey = Carbon::now()->toDateString();
        Cache::forget("finary:predict:{$userId}:{$dateKey}");
    }
}
