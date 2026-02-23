<?php

namespace Langsys\SwaggerAutoGenerator\Tests\Data;

use Spatie\LaravelData\Data;
use Langsys\SwaggerAutoGenerator\Generators\Swagger\Attributes\Example;

class ExampleData extends Data
{
    public function __construct(
        #[Example("test")]
        public string $example
    ) {}
}
