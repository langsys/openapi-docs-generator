<?php

namespace Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures;

use Spatie\LaravelData\Data;

/** A request DTO without #[QueryParameters]: its properties are not documented as parameters. */
class PlainFilterRequest extends Data
{
    public function __construct(
        public ?string $search = null,
    ) {
    }
}
