<?php

namespace Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\QueryParameters;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/** Two properties read from the same input name. */
#[QueryParameters]
class SameInputNameRequest extends Data
{
    public function __construct(
        public ?string $q = null,
        #[MapInputName('q')]
        public ?string $query = null,
    ) {
    }
}
