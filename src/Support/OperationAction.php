<?php

namespace Langsys\OpenApiDocsGenerator\Support;

use OpenApi\Annotations as OA;
use OpenApi\Context;
use ReflectionMethod;

/**
 * Derives the controller action behind a documented operation from the
 * swagger-php parse context, and reflects on it.
 *
 * The action string ("Namespace\Class@method") is the exact key Laravel uses in
 * Route::getActionName(), which is why route resolution prefers it over path
 * matching, and it is how #[Throws] is located on the backing method.
 */
final class OperationAction
{
    /**
     * "Namespace\Class@method" for an operation, or null when it has no class or
     * method context (a closure route or a hand-authored path-only annotation).
     */
    public static function fromOperation(OA\Operation $operation): ?string
    {
        $context = $operation->_context ?? null;

        if (! $context instanceof Context) {
            return null;
        }

        $class = $context->class;
        $method = $context->method;
        $namespace = $context->namespace;

        if (! is_string($class) || $class === '' || ! is_string($method) || $method === '') {
            return null;
        }

        $fqcn = (is_string($namespace) && $namespace !== '') ? $namespace . '\\' . $class : $class;

        return ltrim($fqcn, '\\') . '@' . $method;
    }

    /**
     * Reflect the method behind an action string, or null when it can't be loaded.
     */
    public static function reflect(?string $action): ?ReflectionMethod
    {
        if ($action === null || ! str_contains($action, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        return new ReflectionMethod($class, $method);
    }
}
