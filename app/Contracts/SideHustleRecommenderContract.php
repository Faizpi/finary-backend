<?php

namespace App\Contracts;

interface SideHustleRecommenderContract
{
    /**
     * Recommend side hustles. Always returns a result; falls back to a
     * rule-based catalog when ML is unavailable.
     *
     * @param  array<string, mixed>  $payload
     * @return array{source: string, recommendations: array<int, array<string, mixed>>}
     */
    public function recommend(array $payload): array;
}
