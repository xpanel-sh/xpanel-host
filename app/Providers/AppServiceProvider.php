<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('xmail-login', function (Request $request): array {
            $identity = Str::lower((string) $request->input('email', 'unknown'));

            return [
                Limit::perMinute(5)->by(hash('sha256', $identity.'|'.$request->ip())),
                Limit::perMinute(20)->by(hash('sha256', 'ip|'.$request->ip())),
            ];
        });
    }
}
