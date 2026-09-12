<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures;

use Spatie\LaravelData\Data;

class StoreProjectRequest extends Data
{
    public function __construct(
        public string $name,
    ) {
    }
}
