<?php

namespace App\Providers;

use App\Contracts\FinancialClassifierContract;
use App\Contracts\InterestCategoryInferrerContract;
use App\Contracts\MlGatewayContract;
use App\Contracts\PredictionCacheContract;
use App\Contracts\SideHustleRecommenderContract;
use App\Contracts\TransactionExporterContract;
use App\Contracts\UserRegistrationContract;
use App\Services\FinancialClassifierService;
use App\Services\InterestCategoryInferrer;
use App\Services\MlGatewayService;
use App\Services\PredictionCacheService;
use App\Services\SideHustleRecommendationService;
use App\Services\TransactionExportService;
use App\Services\UserRegistrationService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MlGatewayContract::class, MlGatewayService::class);
        $this->app->bind(FinancialClassifierContract::class, FinancialClassifierService::class);
        $this->app->bind(SideHustleRecommenderContract::class, SideHustleRecommendationService::class);
        $this->app->bind(PredictionCacheContract::class, PredictionCacheService::class);
        $this->app->bind(TransactionExporterContract::class, TransactionExportService::class);
        $this->app->bind(InterestCategoryInferrerContract::class, InterestCategoryInferrer::class);
        $this->app->bind(UserRegistrationContract::class, UserRegistrationService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
