<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Run the whole app in the configured local timezone. Attendance times
        // are stored and shown in this zone (matching the legacy data, which is
        // local — NOT UTC), so no conversion happens on display.
        try {
            if (Schema::hasTable('settings')) {
                $tz = Setting::get('timezone', 'Europe/Berlin');
                config(['app.timezone' => $tz]);
                date_default_timezone_set($tz);
            }
        } catch (\Throwable $e) {
            // Database not ready (e.g. during migration) — keep the default.
        }
    }
}
