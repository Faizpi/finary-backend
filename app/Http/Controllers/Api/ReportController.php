<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function exportTransactions(Request $request)
    {
        $month = $request->query('month', Carbon::now()->format('Y-m'));
        $date = Carbon::createFromFormat('Y-m', (string) $month);

        $transactions = $request->user()->transactions()
            ->whereBetween('transaction_date', [
                $date->copy()->startOfMonth()->toDateString(),
                $date->copy()->endOfMonth()->toDateString(),
            ])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $lines = [
            ['Tanggal', 'Tipe', 'Kategori', 'Jumlah', 'Catatan'],
        ];

        foreach ($transactions as $transaction) {
            $lines[] = [
                $transaction->transaction_date->format('Y-m-d'),
                $transaction->type,
                $transaction->category,
                number_format((float) $transaction->amount, 2, '.', ''),
                str_replace(["\r", "\n"], ' ', (string) $transaction->note),
            ];
        }

        $csv = '';
        foreach ($lines as $line) {
            $escaped = array_map(function (string $value) {
                $value = str_replace('"', '""', $value);
                return '"' . $value . '"';
            }, $line);

            $csv .= implode(',', $escaped) . "\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="transactions-' . $month . '.csv"',
        ]);
    }
}
