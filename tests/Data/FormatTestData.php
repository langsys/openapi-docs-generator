<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Carbon\Carbon;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Example;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Url;
use Spatie\LaravelData\Attributes\Validation\Uuid;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class FormatTestData extends Data
{
    public function __construct(
        #[Uuid]
        public string $organization_id,
        #[Date]
        public ?string $start_date,
        #[Email]
        public string|Optional $email,
        #[Url]
        public ?string $website_url,
        #[Uuid]
        public array $ids,
        #[Date]
        public Carbon $created_at,
        #[Date]
        public string $published_at,
        #[Uuid]
        #[Example('legacy-ref')]
        public string $legacy_ref,
        #[DateFormat('Y-m-d')]
        public string $day,
        #[DateFormat(DATE_ATOM)]
        public string $stamp,
        #[DateFormat('c', DATE_RFC3339)]
        public ?string $iso_stamp,
        #[DateFormat(['Y-m-d', 'c'])]
        public string $day_or_stamp,
        #[DateFormat('d/m/Y')]
        public string $local_day,
        public string $name,
    ) {}
}
