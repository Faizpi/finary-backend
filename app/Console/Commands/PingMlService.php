<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PingMlService extends Command
{
    protected $signature = 'ml:ping';
    protected $description = 'Send a keep-alive ping to the ML microservice to prevent cold starts.';

    public function handle(): int
    {
        if (!config('ml.enabled')) {
            return self::SUCCESS;
        }

        try {
            $response = Http::timeout(10)->get(rtrim(config('ml.base_url'), '/') . '/health');

            if ($response->successful()) {
                Log::info('ML ping OK', ['status' => $response->status()]);
            } else {
                Log::warning('ML ping returned non-2xx', ['status' => $response->status()]);
            }
        } catch (\Throwable $e) {
            Log::warning('ML ping failed', ['error' => $e->getMessage()]);
        }

        return self::SUCCESS;
    }
}
