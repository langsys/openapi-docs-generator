<?php

namespace Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures;

use Langsys\OpenApiDocsGenerator\Contracts\EndpointParameterResolver;
use Langsys\OpenApiDocsGenerator\Data\EndpointParameterData;

/** Names an orderable field ProjectResource doesn't have. */
class UnknownFieldEndpointParameterResolver implements EndpointParameterResolver
{
    public function resolve(string $endpointPath, string $resourceName): ?EndpointParameterData
    {
        return new EndpointParameterData(
            orderableFields: ['name', 'created_at'],
            filterableFields: ['name'],
        );
    }
}
