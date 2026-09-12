<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\HttpStatus;
use Spatie\LaravelData\Data;

/** #[HttpStatus] is inherited by concrete errors that don't declare their own. */
#[HttpStatus(401)]
abstract class BaseAuthError extends Data
{
}
