<?php

namespace App\Contracts;

/**
 * Contract for the ML gateway. Allows swapping the concrete HTTP-based
 * implementation with fakes/mocks for tests, or with a different transport
 * (queue, gRPC, local model) without touching consumers.
 */
interface MlGatewayContract
{
    /**
     * Classify the user's financial state based on assessment payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null  Returns null when ML is disabled or unreachable.
     */
    public function classifyAssessment(array $payload): ?array;

    /**
     * Recommend side hustles based on user context.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function recommendSideHustles(array $payload): ?array;

    /**
     * Predict next-month financial state.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function predictInsight(array $payload): ?array;
}
