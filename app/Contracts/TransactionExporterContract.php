<?php

namespace App\Contracts;

use App\Models\User;

interface TransactionExporterContract
{
    /**
     * Build a CSV body for the user's transactions in the given month.
     */
    public function exportMonth(User $user, string $month): string;

    /**
     * Build the filename to use for the export.
     */
    public function fileName(string $month): string;
}
