<?php

namespace Langsys\OpenApiDocsGenerator\Data;

/**
 * Identity of a documented operation, used to resolve its backing Laravel route.
 *
 * Resolution prefers the controller action (exact, avoids path-string
 * normalization pitfalls) and falls back to the HTTP method + path signature.
 */
class ResolvableOperation
{
    /**
     * @param  string  $httpMethod  Lowercase HTTP verb, e.g. "get".
     * @param  string  $path  Path as written in the OpenAPI document, e.g. "/api/projects/{project}".
     * @param  string|null  $action  Fully-qualified controller action, e.g.
     *                               "App\Http\Controllers\ProjectController@index", when known.
     */
    public function __construct(
        public readonly string $httpMethod,
        public readonly string $path,
        public readonly ?string $action = null,
    ) {}
}
