<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Spatie\LaravelData\Data;

abstract class AbstractBlockResource extends Data
{
    public function __construct(
        public string $id,
    ) {}
}
