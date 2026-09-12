<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures;

use Langsys\OpenApiDocsGenerator\Contracts\ImpliedErrorRule;
use Langsys\OpenApiDocsGenerator\Data\OperationContext;
use ReflectionMethod;

/** A rule taking no constructor arguments, for the bare-class-name descriptor. */
class NoopRule implements ImpliedErrorRule
{
    public function errorsFor(OperationContext $context, ?ReflectionMethod $action): array
    {
        return [];
    }
}
