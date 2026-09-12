<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\Description;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\ErrorCode;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\HttpStatus;
use Spatie\LaravelData\Data;

#[ErrorCode('batch_too_large', 'The batch exceeds the maximum size')]
#[HttpStatus(422)]
#[Description('The submitted batch has more items than the endpoint allows.')]
class BatchTooLargeError extends Data
{
    public function __construct(
        public int $max,
        public int $submitted,
    ) {
    }
}
