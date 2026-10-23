<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\InsurerGateway;
use App\Mock\Insurer\MockInsurerGateway;
use App\Repositories\PolicyRequestRepository;
use App\Repositories\PolicyRequestRepositoryInterface;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PolicyRequestRepositoryInterface::class, PolicyRequestRepository::class);
        $this->app->bind(InsurerGateway::class, MockInsurerGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Spec convention: Single resources are returned flat, not wrapped in data
        JsonResource::withoutWrapping();
    }
}
