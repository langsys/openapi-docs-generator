<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures;

use Spatie\LaravelData\Data;

/** A Data class that does not extend the error base class: naming it in #[Throws] must fail. */
class NotAnError extends Data
{
    public function __construct(public string $whatever)
    {
    }
}
