<?php

namespace App\Providers;

use App\Services\Pricing\BrandingPriceLookup;
use App\Suppliers\SupplierRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SupplierRegistry::class);
        // One price-break cache per request / job.
        $this->app->scoped(BrandingPriceLookup::class);
    }

    public function boot(): void
    {
        //
    }
}
