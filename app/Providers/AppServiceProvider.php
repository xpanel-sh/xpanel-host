<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
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
        $name = (string) config('app.name', 'XPanel Host');
        try {
            if (Schema::hasTable('system_settings')) {
                $name = \App\Models\SystemSetting::get('display_name', $name) ?: $name;
            }
        } catch (\Throwable) {
            // Keep installation and migration error pages renderable.
        }
        View::share('panelName', $name);

        RateLimiter::for('xmail-login', function (Request $request): array {
            $identity = Str::lower((string) $request->input('email', 'unknown'));

            return [
                Limit::perMinute(5)->by(hash('sha256', $identity.'|'.$request->ip())),
                Limit::perMinute(20)->by(hash('sha256', 'ip|'.$request->ip())),
            ];
        });
    }
}
