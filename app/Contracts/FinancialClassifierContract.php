<?php

namespace App\Contracts;

interface FinancialClassifierContract
{
    /**
     * Classify a user's financial state. Always returns a result; falls back
     * to rule-based classification when ML is unavailable.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function classify(array $payload): array;
}
