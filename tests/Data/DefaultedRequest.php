<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Spatie\LaravelData\Data;

class DefaultedRequest extends Data
{
    public function __construct(
        public string $name,
        public ExampleEnum $status = ExampleEnum::CASE_1,
        public bool $active = true,
    ) {}
}
