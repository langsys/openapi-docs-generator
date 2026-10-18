<?php

namespace Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures;

use Spatie\LaravelData\Data;

/** A nested Data class, sent in a query string as `filter[name]=…`. */
class ProjectFilter extends Data
{
    public function __construct(
        public ?string $name = null,
    ) {
    }
}
