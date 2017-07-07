<?php

namespace BoxedCode\Laravel\Scout;

use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;

class DatabaseEngineServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Register our migrations.
        $this->loadMigrationsFrom(__DIR__.'/../migrations');

        // Register the engine.
        resolve(EngineManager::class)->extend('database', function () {
            return new DatabaseEngine($this->app['db']);
        });
    }
}