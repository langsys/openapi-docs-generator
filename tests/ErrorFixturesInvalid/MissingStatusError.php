<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorFixturesInvalid;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\ErrorCode;
use Spatie\LaravelData\Data;

#[ErrorCode('missing_status')]
class MissingStatusError extends Data
{
    public function __construct(public string $reason)
    {
    }
}
