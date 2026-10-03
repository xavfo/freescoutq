<?php

namespace Modules\RestApi\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\RestApi\Http\Middleware\CheckApiTokenMiddleware;
use Modules\RestApi\Http\Middleware\RateLimitMiddleware;

class RestApiServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register()
    {
        // Config must be merged during register(), otherwise config('rest-api')
        // is not available yet when it is copied below.
        $this->mergeConfigFrom(
            __DIR__ . '/../Config/config.php',
            'rest-api'
        );

        $this->app['config']->set(
            'modules.rest-api',
            config('rest-api')
        );
    }

    /**
     * Boot the application events.
     */
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../Http/routes.php');

        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->registerMiddleware();
        $this->registerMigrations();
        $this->registerCommands();
        $this->registerEventListeners();
    }

    /**
     * Register config.
     */
    protected function registerConfig()
    {
        $this->publishes([
            __DIR__ . '/../Config/config.php' => config_path('rest-api.php'),
        ], 'config');
    }

    /**
     * Register views.
     */
    public function registerViews()
    {
        $viewPath = resource_path('views/modules/rest-api');

        $sourcePath = __DIR__ . '/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath
        ], 'views');

        $this->loadViewsFrom(array_merge(
            array_map(function ($path) {
                return $path . '/modules/rest-api';
            }, \Config::get('view.paths')),
            [$sourcePath]
        ), 'restapi');
    }

    /**
     * Register translations.
     */
    public function registerTranslations()
    {
        $langPath = resource_path('lang/modules/rest-api');

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, 'rest-api');
        } else {
            $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'rest-api');
        }
    }

    /**
     * Register middleware.
     */
    public function registerMiddleware()
    {
        $this->app['router']->aliasMiddleware('api-token', CheckApiTokenMiddleware::class);
        $this->app['router']->aliasMiddleware('api-rate-limit', RateLimitMiddleware::class);
    }

    /**
     * Register migrations.
     */
    public function registerMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }

    /**
     * Register console commands
     */
    protected function registerCommands()
    {
        $this->commands([
            \Modules\RestApi\Console\Commands\CreateApiToken::class,
            \Modules\RestApi\Console\Commands\RevokeApiToken::class,
            \Modules\RestApi\Console\Commands\ListApiTokens::class,
        ]);
    }

    /**
     * Register event listeners
     */
    protected function registerEventListeners() {}

    /**
     * Get the services provided by the provider.
     */
    public function provides()
    {
        return [];
    }
}
