<?php

namespace Langsys\OpenApiDocsGenerator\Filters;

use Langsys\OpenApiDocsGenerator\Contracts\OperationFilter;
use Langsys\OpenApiDocsGenerator\Data\OperationContext;
use OpenApi\Generator;

/**
 * Matches operations that carry any of the given tags.
 */
class TagFilter implements OperationFilter
{
    /** @var array<int, string> */
    private array $tags;

    /**
     * @param  string|array<int, string>  $tags
     */
    public function __construct(array|string $tags)
    {
        $this->tags = is_array($tags) ? array_values($tags) : [$tags];
    }

    public function matches(OperationContext $context): bool
    {
        $operationTags = $context->operation->tags;

        if ($operationTags === Generator::UNDEFINED || ! is_array($operationTags)) {
            return false;
        }

        return array_intersect($this->tags, $operationTags) !== [];
    }
}
