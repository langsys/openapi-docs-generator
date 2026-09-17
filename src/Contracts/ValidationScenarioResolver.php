<?php

namespace Langsys\OpenApiDocsGenerator\Contracts;

use Langsys\OpenApiDocsGenerator\Data\OperationContext;
use Langsys\OpenApiDocsGenerator\Data\ValidationScenario;
use ReflectionMethod;

/**
 * Lists the field-level validation failures an operation can return.
 *
 * `implied_errors.validation` gives an operation one error class for its validation
 * status, so every endpoint renders the same flat message. An app usually knows far
 * more: which field can fail, with which code, and why. That knowledge lives in the
 * app's own rules, so the library takes a resolver instead of guessing, exactly as it
 * does for {@see ImpliedErrorRule}.
 *
 * Scenarios are listed in the description of the operation's validation response.
 * They never change its schema, and an operation whose resolver returns nothing is
 * documented exactly as before.
 */
interface ValidationScenarioResolver
{
    /**
     * The validation failures the given operation can return, or an empty array.
     *
     * @param  OperationContext  $context  The operation, its path/method, and the
     *                                     resolved route (null when unresolvable).
     * @param  ReflectionMethod|null  $action  The matched route's controller action,
     *                                         as passed to an ImpliedErrorRule.
     * @return array<int, ValidationScenario>
     */
    public function scenariosFor(OperationContext $context, ?ReflectionMethod $action): array;
}
