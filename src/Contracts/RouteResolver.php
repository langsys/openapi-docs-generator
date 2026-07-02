<?php

namespace Langsys\OpenApiDocsGenerator\Contracts;

use Langsys\OpenApiDocsGenerator\Data\ResolvableOperation;
use Langsys\OpenApiDocsGenerator\Data\ResolvedRoute;

interface RouteResolver
{
    /**
     * Resolve the Laravel route backing a documented operation.
     *
     * Resolution is ground truth for filtered documentation sets: it maps a
     * documented operation (controller action, or HTTP method + path) to the
     * Laravel Route that serves it, so filters can inspect the route's actual
     * middleware instead of trusting hand-written annotations.
     *
     * @param  ResolvableOperation  $operation  The operation to resolve.
     * @return ResolvedRoute|null  The matched route + its gathered middleware,
     *                             or null when no route matches (e.g. closure
     *                             routes, or annotation-only operations with no
     *                             backing route).
     */
    public function resolve(ResolvableOperation $operation): ?ResolvedRoute;
}
