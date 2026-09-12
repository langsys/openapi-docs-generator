<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures;

/** Stands in for an app's in-body authorization facade. */
class FakeGuard
{
    public static function authorize(string $permission): void
    {
    }
}
