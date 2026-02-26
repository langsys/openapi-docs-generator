<?php

namespace Langsys\OpenApiDocsGenerator;

use Illuminate\Support\Facades\Facade;
use Langsys\OpenApiDocsGenerator\Generators\OpenApiGenerator;

class OpenApiDocsFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return OpenApiGenerator::class;
    }
}
