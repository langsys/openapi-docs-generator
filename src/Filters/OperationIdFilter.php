<?php

namespace Langsys\OpenApiDocsGenerator\Filters;

use Langsys\OpenApiDocsGenerator\Contracts\OperationFilter;
use Langsys\OpenApiDocsGenerator\Data\OperationContext;
use OpenApi\Generator;

/**
 * Matches operations whose operationId is in the given list.
 */
class OperationIdFilter implements OperationFilter
{
    /** @var array<int, string> */
    private array $operationIds;

    /**
     * @param  string|array<int, string>  $operationIds
     */
    public function __construct(array|string $operationIds)
    {
        $this->operationIds = is_array($operationIds) ? array_values($operationIds) : [$operationIds];
    }

    public function matches(OperationContext $context): bool
    {
        $operationId = $context->operation->operationId;

        if ($operationId === Generator::UNDEFINED) {
            return false;
        }

        return in_array($operationId, $this->operationIds, true);
    }
}
