<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures;

use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\ApiError;

/** A second 422 error, so a status can be shared. */
class BatchTooLargeError extends ApiError
{
    public const CODE = 'batch_too_large';
    public const MESSAGE = 'The submitted batch has more items than the endpoint allows.';
    public const STATUS = 422;

    public function __construct(
        public int $max,
        public int $submitted,
    ) {
    }
}
