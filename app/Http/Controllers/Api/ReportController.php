<?php

namespace App\Http\Controllers\Api;

use App\Contracts\TransactionExporterContract;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly TransactionExporterContract $exporter)
    {
    }

    public function exportTransactions(Request $request)
    {
        $month = (string) $request->query('month', Carbon::now()->format('Y-m'));

        $csv = $this->exporter->exportMonth($request->user(), $month);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $this->exporter->fileName($month) . '"',
        ]);
    }
}
