<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTime;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Example;
use Spatie\LaravelData\Data;

class DateTimeTestData extends Data
{
    public function __construct(
        public string $name,
        public Carbon $created_at,
        public ?Carbon $deleted_at = null,
        public CarbonImmutable $published_at,
        public DateTime $legacy_date,
        #[Example('2025-06-01T00:00:00+00:00')]
        public Carbon $with_example,
    ) {}
}
