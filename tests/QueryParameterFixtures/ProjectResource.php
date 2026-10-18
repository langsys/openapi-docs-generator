<?php

namespace Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\Example;
use Spatie\LaravelData\Data;

class ProjectResource extends Data
{
    public function __construct(
        #[Example('Acme')]
        public string $name,
    ) {
    }
}
