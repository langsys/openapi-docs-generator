<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\Description;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\ErrorCode;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\HttpStatus;
use Spatie\LaravelData\Data;

#[ErrorCode('insufficient_balance', 'Insufficient balance to complete this request')]
#[HttpStatus(402)]
#[Description('The account balance cannot cover the requested operation.')]
class InsufficientBalanceError extends Data
{
    public function __construct(
        public int $required,
        public int $available,
    ) {
    }
}
