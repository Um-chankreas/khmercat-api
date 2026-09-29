<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Login / register: 5 tries a minute per email *and* device, so one
        // person's typos never lock out others sharing the same Wi-Fi or
        // mobile network (a plain per-IP limit did). A looser per-IP cap
        // still stops someone hammering many emails from one place.
        RateLimiter::for('auth', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('auth|'.$email.'|'.$request->ip()),
                Limit::perMinute(30)->by('auth-ip|'.$request->ip()),
            ];
        });
    }
}
