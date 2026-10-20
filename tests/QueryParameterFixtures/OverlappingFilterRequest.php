<?php

namespace Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\QueryParameters;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/** Reads `filter` as an object and `filter[search]` from inside it. */
#[QueryParameters]
class OverlappingFilterRequest extends Data
{
    public function __construct(
        public ?ProjectFilter $filter = null,
        #[MapInputName('filter.search')]
        public ?string $search = null,
    ) {
    }
}
