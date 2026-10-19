<?php

namespace Langsys\OpenApiDocsGenerator\Tests\Data;

use Carbon\Carbon;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Example;
use Spatie\LaravelData\Attributes\Validation\Date;
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
        public string $name,
    ) {}
}
