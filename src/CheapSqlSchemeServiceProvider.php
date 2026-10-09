<?php

namespace CheapSqlScheme;

use Illuminate\Support\ServiceProvider;

class CheapSqlSchemeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cheap-sql-scheme.php', 'cheap-sql-scheme');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cheap-sql-scheme');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cheap-sql-scheme.php' => config_path('cheap-sql-scheme.php'),
            ], 'cheap-sql-scheme-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'cheap-sql-scheme-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/cheap-sql-scheme'),
            ], 'cheap-sql-scheme-views');
        }
    }
}
