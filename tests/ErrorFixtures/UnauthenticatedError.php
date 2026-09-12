<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures;

/** Inherits STATUS from an abstract parent. */
class UnauthenticatedError extends BaseAuthError
{
    public const CODE = 'unauthenticated';
    public const MESSAGE = 'Unauthenticated';
}
