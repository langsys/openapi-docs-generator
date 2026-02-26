<?php

declare(strict_types=1);

namespace Langsys\OpenApiDocsGenerator\Tests;

use Langsys\OpenApiDocsGenerator\OpenApiDocsServiceProvider;
use Orchestra\Testbench\TestCase;

class LangsysDataSwaggerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            OpenApiDocsServiceProvider::class,
        ];
    }
}
