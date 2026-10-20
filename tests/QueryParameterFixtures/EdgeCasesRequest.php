<?php

namespace Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures;

use Carbon\Carbon;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Example;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\QueryParameters;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Spatie\LaravelData\Data;

/**
 * Examples a DTO schema does not show as written (a scalar on a string map and on a
 * nested Data class, a string on an int, a value that is not an enum case), and
 * DateTime properties under #[DateFormat] and #[Date].
 */
#[QueryParameters]
class EdgeCasesRequest extends Data
{
    public function __construct(
        /** @var array<string, string> */
        #[Example('x')]
        public array $meta = [],
        #[Example('x')]
        public ?ProjectFilter $filter = null,
        #[Example('abc')]
        public ?int $limit = null,
        #[Example('deleted')]
        public ?ProjectStatus $status = null,
        #[DateFormat('Y-m-d'), Example(':dateTime')]
        public ?Carbon $until = null,
        #[Date]
        public ?Carbon $since = null,
    ) {
    }
}
