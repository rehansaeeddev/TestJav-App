<?php

namespace App\Providers;

use App\Services\Shopify\ShopifyStoreData;
use App\Services\Shopify\StoreData;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /** Answers always come from the connected Shopify store. Tests swap in Tests\Fakes\FakeStoreData. */
        $this->app->bind(StoreData::class, ShopifyStoreData::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /** Each staff member of each shop gets 20 chat messages per minute. */
        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute(20)->by(
            $request->attributes->get('shopify_shop')?->id.'|'.$request->attributes->get('shopify_user')
        ));
    }
}
