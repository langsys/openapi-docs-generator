<?php

namespace Langsys\SwaggerAutoGenerator;

use Illuminate\Support\ServiceProvider;

class SwaggerAutoGeneratorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\DataObjectMakeCommand::class,
                Console\Commands\GenerateDataSwagger::class,
            ]);
        }

        $this->publishes([
            __DIR__ . '/config/langsys-generator.php' => config_path('langsys-generator.php'),
        ], 'config');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/langsys-generator.php', 'langsys-generator');
    }
}

