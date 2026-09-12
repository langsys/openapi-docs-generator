<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures;

/** Stands in for an app's int-backed HTTP status enum. */
enum HttpCode: int
{
    case UNAUTHORIZED = 401;
    case NOT_FOUND = 404;
    case UNPROCESSABLE_ENTITY = 422;
}
