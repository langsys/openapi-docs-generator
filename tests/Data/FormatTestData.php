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
        #[DateFormat(DATE_RFC3339_EXTENDED)]
        public string $precise_stamp,
        #[DateFormat(DATE_RFC3339, DATE_RFC3339_EXTENDED)]
        public ?string $either_stamp,
        #[DateFormat(['Y-m-d', DATE_ATOM])]
        public string $day_or_stamp,
        #[DateFormat('c')]
        public string $c_stamp,
        #[DateFormat('d/m/Y')]
        public string $local_day,
        #[Date, DateFormat('d/m/Y')]
        public string $date_then_local_day,
        #[DateFormat(DATE_ATOM), Date]
        public string $stamp_then_date,
        #[DateFormat('Y-m-d'), Example(':date')]
        public string $hinted_day,
        public string $name,
    ) {}
}
