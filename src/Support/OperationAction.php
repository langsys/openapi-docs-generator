<?php

namespace Langsys\OpenApiDocsGenerator\Support;

use Langsys\OpenApiDocsGenerator\Data\ResolvedRoute;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Spatie\LaravelData\Data;

/**
 * Derives the controller action behind a documented operation from the
 * swagger-php parse context, and reflects on it.
 *
 * The action string ("Namespace\Class@method") is the exact key Laravel uses in
 * Route::getActionName(), which is why route resolution prefers it over path
 * matching, and it is how #[Throws] and the action's Data parameters are located
 * on the backing method.
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

    /**
     * The method that actually handles the operation. Normally the annotated method,
     * but an @OA annotation can sit on a helper rather than on the route's action; when
     * the matched route names a different, reflectable controller action, that action
     * is the one to inspect.
     */
    public static function handler(?ReflectionMethod $annotated, ?string $annotatedAction, ?ResolvedRoute $route): ?ReflectionMethod
    {
        $routeAction = $route?->action();

        if ($routeAction === null || $routeAction === $annotatedAction) {
            return $annotated;
        }

        return self::reflect($routeAction) ?? $annotated;
    }

    /**
     * The Spatie Data classes the action takes as parameters, in signature order,
     * including each Data member of a union-typed parameter.
     *
     * @return array<int, class-string<Data>>
     */
    public static function dataParameterClasses(?ReflectionMethod $method): array
    {
        if ($method === null) {
            return [];
        }

        $classes = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

            foreach ($types as $named) {
                if ($named instanceof ReflectionNamedType && ! $named->isBuiltin() && is_a($named->getName(), Data::class, true)) {
                    $classes[] = $named->getName();
                }
            }
        }

        return array_values(array_unique($classes));
    }
}
