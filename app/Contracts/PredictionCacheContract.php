<?php

namespace App\Contracts;

use Closure;
use DateTimeInterface;

interface PredictionCacheContract
{
    /**
     * Returns the daily prediction cache key for a user, using "today" by
     * default. Centralizing this prevents key drift between writers/readers.
     */
    public function dailyKey(int $userId, ?DateTimeInterface $date = null): string;

    /**
     * Cache the result of $resolver under today's prediction key for the user
     * until the end of the current day.
     *
     * @template T
     * @param  Closure(): T  $resolver
     * @return T
     */
    public function rememberDaily(int $userId, Closure $resolver): mixed;

    /**
     * Invalidate today's prediction cache for the user.
     */
    public function forgetDaily(int $userId): void;
}
