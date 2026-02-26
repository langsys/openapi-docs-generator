<?php

namespace Langsys\OpenApiDocsGenerator\Data;

class EndpointParameterData
{
    /**
     * @param  array<string>  $orderableFields   e.g. ['title', 'created_at', 'admin']
     * @param  array<array{0: string, 1: string}>  $defaultOrder  e.g. [['admin', 'desc'], ['last_activity_at', 'desc']]
     * @param  array<string>  $filterableFields  e.g. ['title', 'status', 'type']
     * @param  array<array{0: string, 1: string}>  $defaultFilters  e.g. [['status', 'active']]
     */
    public function __construct(
        public array $orderableFields = [],
        public array $defaultOrder = [],
        public array $filterableFields = [],
        public array $defaultFilters = [],
    ) {}
}
