<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures;

use Spatie\LaravelData\Data;

/** Deliberately carries no #[ErrorCode] — naming it in #[Throws] must fail. */
class NotAnError extends Data
{
    public function __construct(public string $whatever)
    {
    }
}
