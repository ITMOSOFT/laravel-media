<?php

declare(strict_types=1);

namespace ItmoSoft\Media;

use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/media.php',
            'media'
        );

        $this->app->singleton(
            MediaManager::class,
            fn ($app) => new MediaManager
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/media.php' => config_path('media.php'),
        ], 'media-config');

        $this->loadMigrationsFrom(
            __DIR__.'/../database/migrations'
        );
    }
}
