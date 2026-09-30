<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures;

/** A specific failure mode: declares its own CODE and MESSAGE, inherits STATUS from a concrete parent. */
class ProjectNotFoundError extends NotFoundError
{
    public const CODE = 'project_not_found';
    public static function template(): string
    {
        return 'No project with that id';
    }
}
