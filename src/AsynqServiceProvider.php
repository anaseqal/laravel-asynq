<?php

namespace AnasEqal\LaravelAsynq;

use Illuminate\Support\ServiceProvider;
use AnasEqal\LaravelAsynq\Services\AsynqTaskService;

class AsynqServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/Config/asynq.php' => config_path('asynq.php'),
            ], 'config');
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/Config/asynq.php', 'asynq');

        $this->app->singleton('asynq', function ($app) {
            return new AsynqTaskService();
        });
    }
}
