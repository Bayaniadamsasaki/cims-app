<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Interface\UserRepositoryInterface::class, \App\Repositories\UserRepository::class);
        $this->app->bind(\App\Interface\BuildingRepositoryInterface::class, \App\Repositories\BuildingRepository::class);
        $this->app->bind(\App\Interface\FloorRepositoryInterface::class, \App\Repositories\FloorRepository::class);
        $this->app->bind(\App\Interface\RoomRepositoryInterface::class, \App\Repositories\RoomRepository::class);
        $this->app->bind(\App\Interface\RackRepositoryInterface::class, \App\Repositories\RackRepository::class);
        $this->app->bind(\App\Interface\VendorRepositoryInterface::class, \App\Repositories\VendorRepository::class);
        $this->app->bind(\App\Interface\DeviceCategoryRepositoryInterface::class, \App\Repositories\DeviceCategoryRepository::class);
        $this->app->bind(\App\Interface\DeviceRepositoryInterface::class, \App\Repositories\DeviceRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
