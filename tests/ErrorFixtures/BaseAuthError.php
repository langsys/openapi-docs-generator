<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures;

/** An abstract parent that supplies STATUS to the concrete errors below it. */
abstract class BaseAuthError extends ApiError
{
    public const STATUS = HttpCode::UNAUTHORIZED;
}
