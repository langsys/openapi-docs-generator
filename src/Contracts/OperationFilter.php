<?php

namespace Langsys\OpenApiDocsGenerator\Contracts;

use Langsys\OpenApiDocsGenerator\Data\OperationContext;

interface OperationFilter
{
    /**
     * Whether the given operation matches this filter.
     *
     * Filters are composed by the selector using include-union / exclude-subtract
     * semantics: an operation survives when it matches ANY include filter and NO
     * exclude filter.
     */
    public function matches(OperationContext $context): bool;
}
