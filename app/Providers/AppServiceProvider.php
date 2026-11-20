<?php

namespace App\Providers;

use App\Interfaces\OptionRepositoryInterface;
use App\Interfaces\StoreRepositoryInterface;
use App\Repositories\OptionRepository;
use App\Repositories\StoreRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register repository bindings
        $this->app->bind(OptionRepositoryInterface::class, OptionRepository::class);
        $this->app->bind(StoreRepositoryInterface::class, StoreRepository::class);

        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {

            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);

            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Implicitly grant "super-admin" role all permissions via Laravel Gate (also honored by Spatie Permission middleware)
        Gate::before(function ($user, string $ability) {
            return method_exists($user, 'hasRole') && $user->hasRole('super-admin') ? true : null;
        });
    }
}
