<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class OptionalUnionTestRequest extends Data
{
    public function __construct(
        public string $required_field,
        public string|Optional $artist,
        public Optional|string $title_reversed_union,
        public int|Optional $count,
        public ExampleEnum|Optional $status = ExampleEnum::CASE_1,
    ) {}
}
