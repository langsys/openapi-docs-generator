<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\ErrorCode;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\HttpStatus;

#[ErrorCode('unauthenticated', 'Unauthenticated')]
class UnauthenticatedError extends BaseAuthError
{
}
