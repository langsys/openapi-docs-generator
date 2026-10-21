<?php

namespace Langsys\OpenApiDocsGenerator\Tests\QueryParameterFixtures;

use Langsys\OpenApiDocsGenerator\Contracts\EndpointParameterResolver;
use Langsys\OpenApiDocsGenerator\Data\EndpointParameterData;

/** Gives every endpoint the same orderable and filterable fields, all properties of ProjectResource. */
class FixedEndpointParameterResolver implements EndpointParameterResolver
{
    public function resolve(string $endpointPath, string $resourceName): ?EndpointParameterData
    {
        return new EndpointParameterData(
            orderableFields: ['name'],
            filterableFields: ['name'],
        );
    }
}
