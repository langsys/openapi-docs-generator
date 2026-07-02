<?php

namespace Langsys\OpenApiDocsGenerator\Data;

use OpenApi\Annotations as OA;

/**
 * Everything an {@see \Langsys\OpenApiDocsGenerator\Contracts\OperationFilter}
 * needs to decide whether an operation belongs in a documentation set.
 *
 * Carries the operation itself, its position (path item + HTTP method + path),
 * and the resolved Laravel route (null when no backing route could be matched).
 */
class OperationContext
{
    public function __construct(
        public readonly OA\Operation $operation,
        public readonly OA\PathItem $pathItem,
        public readonly string $httpMethod,
        public readonly string $path,
        public readonly ?ResolvedRoute $route = null,
    ) {}
}
