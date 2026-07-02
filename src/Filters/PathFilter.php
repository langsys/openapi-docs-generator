<?php

namespace Langsys\OpenApiDocsGenerator\Filters;

use Illuminate\Support\Str;
use Langsys\OpenApiDocsGenerator\Contracts\OperationFilter;
use Langsys\OpenApiDocsGenerator\Data\OperationContext;

/**
 * Matches operations whose path matches any of the given glob patterns.
 *
 * Patterns use Laravel's {@see Str::is()} wildcard semantics ("*"), and are tried
 * against both the raw OpenAPI path and its leading-slash-stripped form so that
 * "webhooks/*" and "/webhooks/*" both work.
 */
class PathFilter implements OperationFilter
{
    /** @var array<int, string> */
    private array $patterns;

    /**
     * @param  string|array<int, string>  $patterns
     */
    public function __construct(array|string $patterns)
    {
        $this->patterns = is_array($patterns) ? array_values($patterns) : [$patterns];
    }

    public function matches(OperationContext $context): bool
    {
        $path = $context->path;
        $trimmed = ltrim($path, '/');

        foreach ($this->patterns as $pattern) {
            if (Str::is($pattern, $path) || Str::is($pattern, $trimmed)) {
                return true;
            }
        }

        return false;
    }
}
