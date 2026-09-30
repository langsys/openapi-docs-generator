<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures;

/** A generic, concrete error that specific failure modes extend. */
class NotFoundError extends ApiError
{
    public const CODE = 'not_found';
    public static function template(): string
    {
        return 'Resource not found';
    }
    public const STATUS = HttpCode::NOT_FOUND;
}
