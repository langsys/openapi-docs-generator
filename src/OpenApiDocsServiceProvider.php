<?php

namespace Langsys\OpenApiDocsGenerator;

use Illuminate\Support\ServiceProvider;
use Langsys\OpenApiDocsGenerator\Console\Commands\DtoMakeCommand;
use Langsys\OpenApiDocsGenerator\Console\Commands\GenerateCommand;
use Langsys\OpenApiDocsGenerator\Console\Commands\ThunderClientCommand;
use Langsys\OpenApiDocsGenerator\Generators\GeneratorFactory;
use Langsys\OpenApiDocsGenerator\Generators\OpenApiGenerator;

class OpenApiDocsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([
            DtoMakeCommand::class,
            GenerateCommand::class,
            ThunderClientCommand::class,
        ]);

        $this->publishes([
            __DIR__ . '/config/openapi-docs.php' => config_path('openapi-docs.php'),
        ], 'config');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/openapi-docs.php', 'openapi-docs');

        $this->app->bind(OpenApiGenerator::class, function ($app) {
            $documentation = config('openapi-docs.default', 'default');

            return GeneratorFactory::make($documentation);
        });
    }
}
