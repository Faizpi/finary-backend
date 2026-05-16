<?php

namespace App\Services;

use App\Contracts\TransactionExporterContract;
use App\Models\User;
use Carbon\Carbon;

class TransactionExportService implements TransactionExporterContract
{
    public function exportMonth(User $user, string $month): string
    {
        $date = Carbon::createFromFormat('Y-m', $month);

        $transactions = $user->transactions()
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
            $escaped = array_map(function (string $value): string {
                $value = str_replace('"', '""', $value);
                return '"' . $value . '"';
            }, $line);

            $csv .= implode(',', $escaped) . "\n";
        }

        return $csv;
    }

    public function fileName(string $month): string
    {
        return "transactions-{$month}.csv";
    }
}
