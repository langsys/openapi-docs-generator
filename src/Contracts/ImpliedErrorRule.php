<?php

namespace Langsys\OpenApiDocsGenerator\Contracts;

use Langsys\OpenApiDocsGenerator\Data\OperationContext;
use ReflectionMethod;

/**
 * A custom rule deciding which errors an operation can return.
 *
 * The built-in rules cover what the framework can prove: route middleware, a
 * Data-typed action parameter, a bound route parameter. Anything an app knows by
 * its own convention — an in-body authorization call, a permission registry, a
 * service contract — belongs in a rule the app owns, so the library never has to
 * guess at an implementation idiom it cannot verify.
 *
 * Returned classes are resolved the same way declared ones are: each must be a
 * scanned error class, or generation fails naming the operation.
 */
interface ImpliedErrorRule
{
    /**
     * Error classes the given operation can return. Return an empty array for
     * operations the rule says nothing about.
     *
     * @param  OperationContext  $context  The operation, its path/method, and the
     *                                     resolved route (null when unresolvable).
     * @param  ReflectionMethod|null  $action  The backing controller action, when
     *                                         the operation has one.
     * @return array<int, class-string>
     */
    public function errorsFor(OperationContext $context, ?ReflectionMethod $action): array;
}
