<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures;

/** STATUS as a plain int; has details. */
class InsufficientBalanceError extends ApiError
{
    public const CODE = 'insufficient_balance';
    public const MESSAGE = 'Insufficient balance to complete this request';
    public const STATUS = 402;

    public function __construct(
        public int $required,
        public int $available,
    ) {
    }
}
