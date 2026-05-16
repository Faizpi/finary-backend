<?php

namespace App\Services;

use App\Contracts\PredictionCacheContract;
use Carbon\Carbon;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

class PredictionCacheService implements PredictionCacheContract
{
    public function dailyKey(int $userId, ?DateTimeInterface $date = null): string
    {
        $dateKey = $date
            ? Carbon::instance($date)->toDateString()
            : Carbon::now()->toDateString();

        return "finary:predict:{$userId}:{$dateKey}";
    }

    public function rememberDaily(int $userId, Closure $resolver): mixed
    {
        $key = $this->dailyKey($userId);
        $ttl = Carbon::now()->endOfDay();

        return Cache::remember($key, $ttl, $resolver);
    }

    public function forgetDaily(int $userId): void
    {
        Cache::forget($this->dailyKey($userId));
    }
}
