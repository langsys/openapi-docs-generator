<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Spatie\LaravelData\Data;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Example;

class ExampleData extends Data
{
    public function __construct(
        #[Example("test")]
        public string $example
    ) {}
}
