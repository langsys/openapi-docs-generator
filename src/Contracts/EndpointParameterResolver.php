<?php

namespace Langsys\OpenApiDocsGenerator\Contracts;

use Langsys\OpenApiDocsGenerator\Data\EndpointParameterData;

interface EndpointParameterResolver
{
    /**
     * Resolve endpoint parameter data for the given endpoint and resource.
     *
     * @param  string  $endpointPath   The endpoint path (without leading slash), e.g. "api/v1/projects"
     * @param  string  $resourceName   The inferred resource name, e.g. "ProjectResource"
     * @return EndpointParameterData|null  Parameter data, or null if not available
     */
    public function resolve(string $endpointPath, string $resourceName): ?EndpointParameterData;
}
