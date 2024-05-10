<?php

declare(strict_types=1);

namespace Langsys\SwaggerAutoGenerator\Tests;

use Langsys\SwaggerAutoGenerator\SwaggerAutoGeneratorServiceProvider;
use Orchestra\Testbench\TestCase;

class LangsysDataSwaggerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            SwaggerAutoGeneratorServiceProvider::class,
        ];
    }
}
