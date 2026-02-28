<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use OpenApi\Annotations as OA;
use OpenApi\Generator as OpenApiGen;
use Langsys\OpenApiDocsGenerator\Contracts\EndpointParameterResolver;
use Langsys\OpenApiDocsGenerator\Data\EndpointParameterData;

class EndpointParameterEnricher
{
    /**
     * HTTP methods to inspect on each PathItem.
     */
    private const OPERATION_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    /**
     * Response schema name suffixes to strip when inferring the resource name.
     * Order matters: longer/more specific suffixes first.
     */
    private const RESPONSE_SUFFIXES = ['PaginatedResponse', 'ListResponse', 'Response'];

    public function __construct(
        private EndpointParameterResolver $resolver,
        private array $parameterNames = ['order_by', 'filter_by'],
        private bool $includeExtensions = true,
    ) {}

    /**
     * Enrich the OpenAPI document by replacing generic parameter refs
     * with endpoint-specific inline parameters for order_by/filter_by.
     */
    public function enrich(OA\OpenApi $openapi): void
    {
        if ($openapi->paths === OpenApiGen::UNDEFINED || ! is_array($openapi->paths)) {
            return;
        }

        foreach ($openapi->paths as $pathItem) {
            $this->enrichPathItem($pathItem);
        }
    }

    /**
     * Process all operations within a single path item.
     */
    private function enrichPathItem(OA\PathItem $pathItem): void
    {
        foreach (self::OPERATION_METHODS as $method) {
            $operation = $pathItem->{$method};

            if ($operation === OpenApiGen::UNDEFINED) {
                continue;
            }

            if ($operation->parameters === OpenApiGen::UNDEFINED || ! is_array($operation->parameters)) {
                continue;
            }

            $this->enrichOperation($operation, $pathItem->path);
        }
    }

    /**
     * Enrich a single operation's parameters.
     */
    private function enrichOperation(OA\Operation $operation, string $path): void
    {
        $resourceName = $this->inferResourceName($operation);

        if ($resourceName === null) {
            return;
        }

        $endpointPath = ltrim($path, '/');
        $data = $this->resolver->resolve($endpointPath, $resourceName);

        if ($data === null) {
            return;
        }

        $operation->parameters = $this->replaceRefParameters($operation->parameters, $data);
    }

    /**
     * Infer the resource name from the operation's 200 response $ref.
     *
     * Looks for a JsonContent or MediaType schema ref in the 200 response,
     * extracts the schema name, strips response suffixes, and appends "Resource".
     *
     * Example: "#/components/schemas/ProjectPaginatedResponse" -> "ProjectResource"
     */
    private function inferResourceName(OA\Operation $operation): ?string
    {
        $response200 = $this->find200Response($operation);

        if ($response200 === null) {
            return null;
        }

        $ref = $this->extractResponseRef($response200);

        if ($ref === null) {
            return null;
        }

        // Extract the schema name from the ref path
        $schemaName = basename($ref);

        // Strip response suffixes to get the base resource name.
        // Uses strpos instead of str_ends_with to handle suffixes with
        // extra trailing parts (e.g. "PaginatedResponseWithMeta").
        $baseName = $schemaName;
        foreach (self::RESPONSE_SUFFIXES as $suffix) {
            $pos = strpos($baseName, $suffix);
            if ($pos !== false) {
                $baseName = substr($baseName, 0, $pos);
                break;
            }
        }

        // Append "Resource" to form the canonical resource name
        return $baseName . 'Resource';
    }

    /**
     * Find the 200 response in the operation.
     */
    private function find200Response(OA\Operation $operation): ?OA\Response
    {
        if ($operation->responses === OpenApiGen::UNDEFINED || ! is_array($operation->responses)) {
            return null;
        }

        foreach ($operation->responses as $response) {
            if (! $response instanceof OA\Response) {
                continue;
            }

            $responseCode = $response->response;
            if ($responseCode === OpenApiGen::UNDEFINED) {
                continue;
            }

            if ((string) $responseCode === '200') {
                return $response;
            }
        }

        return null;
    }

    /**
     * Extract the schema $ref from a response's content.
     *
     * Checks JsonContent first (direct ref on the content object),
     * then falls back to MediaType content with nested schema ref.
     * Also handles array-type schemas where the $ref is on items.
     */
    private function extractResponseRef(OA\Response $response): ?string
    {
        if ($response->content === OpenApiGen::UNDEFINED) {
            return null;
        }

        // Handle single JsonContent (which extends Schema, has $ref directly)
        if ($response->content instanceof OA\JsonContent) {
            return $this->extractRefFromSchema($response->content);
        }

        // Handle array of MediaType objects
        if (is_array($response->content)) {
            foreach ($response->content as $mediaType) {
                if (! $mediaType instanceof OA\MediaType) {
                    continue;
                }

                if ($mediaType->schema === OpenApiGen::UNDEFINED) {
                    continue;
                }

                $ref = $this->extractRefFromSchema($mediaType->schema);
                if ($ref !== null) {
                    return $ref;
                }
            }
        }

        return null;
    }

    /**
     * Extract a $ref from a schema, checking both direct ref and items ref (for array types).
     */
    private function extractRefFromSchema(OA\Schema $schema): ?string
    {
        // Direct ref on the schema itself
        if ($schema->ref !== OpenApiGen::UNDEFINED && is_string($schema->ref)) {
            return $schema->ref;
        }

        // Array-type schema: check items.$ref
        if ($schema->items !== OpenApiGen::UNDEFINED && $schema->items instanceof OA\Items) {
            $ref = $schema->items->ref;
            if ($ref !== OpenApiGen::UNDEFINED && is_string($ref)) {
                return $ref;
            }
        }

        return null;
    }

    /**
     * Replace $ref parameters that match configured parameter names
     * with inline, endpoint-specific parameter definitions.
     *
     * @param  OA\Parameter[]  $parameters
     * @return OA\Parameter[]
     */
    private function replaceRefParameters(array $parameters, EndpointParameterData $data): array
    {
        $result = [];

        foreach ($parameters as $parameter) {
            if (! $parameter instanceof OA\Parameter) {
                $result[] = $parameter;
                continue;
            }

            // Only process ref parameters
            if ($parameter->ref === OpenApiGen::UNDEFINED) {
                $result[] = $parameter;
                continue;
            }

            $refName = basename($parameter->ref);

            if (in_array($refName, $this->parameterNames, true)) {
                $inlineParam = $this->buildInlineParameter($refName, $data);
                if ($inlineParam !== null) {
                    $result[] = $inlineParam;
                    continue;
                }
            }

            // Keep the original parameter if no replacement was made
            $result[] = $parameter;
        }

        return $result;
    }

    /**
     * Build an inline parameter to replace a generic $ref parameter.
     */
    private function buildInlineParameter(string $parameterName, EndpointParameterData $data): ?OA\Parameter
    {
        if ($parameterName === 'order_by') {
            return $this->buildOrderByParameter($data);
        }

        if ($parameterName === 'filter_by') {
            return $this->buildFilterByParameter($data);
        }

        return null;
    }

    /**
     * Build an inline order_by parameter with endpoint-specific details.
     */
    private function buildOrderByParameter(EndpointParameterData $data): OA\Parameter
    {
        $description = $this->buildOrderByDescription($data->orderableFields, $data->defaultOrder);
        $example = $this->buildOrderByExample($data->defaultOrder, $data->orderableFields);

        $properties = [
            'parameter' => 'order_by',
            'name' => 'order_by',
            'in' => 'query',
            'required' => false,
            'description' => $description,
            'example' => $example,
            'schema' => new OA\Schema([
                'oneOf' => [
                    new OA\Schema(['type' => 'string']),
                    new OA\Schema([
                        'type' => 'array',
                        'items' => new OA\Items(['type' => 'string']),
                    ]),
                ],
            ]),
        ];

        if ($this->includeExtensions) {
            $properties['x'] = [
                'orderable-fields' => $data->orderableFields,
                'default-order' => array_map(
                    fn (array $entry) => ['field' => $entry[0], 'direction' => $entry[1]],
                    $data->defaultOrder
                ),
            ];
        }

        return new OA\Parameter($properties);
    }

    /**
     * Build an inline filter_by parameter with endpoint-specific details.
     */
    private function buildFilterByParameter(EndpointParameterData $data): OA\Parameter
    {
        $description = $this->buildFilterByDescription($data->filterableFields, $data->defaultFilters);
        $example = $this->buildFilterByExample($data->defaultFilters, $data->filterableFields);

        $properties = [
            'parameter' => 'filter_by',
            'name' => 'filter_by',
            'in' => 'query',
            'required' => false,
            'description' => $description,
            'example' => $example,
            'schema' => new OA\Schema([
                'oneOf' => [
                    new OA\Schema(['type' => 'string']),
                    new OA\Schema([
                        'type' => 'array',
                        'items' => new OA\Items(['type' => 'string']),
                    ]),
                ],
            ]),
        ];

        if ($this->includeExtensions) {
            $properties['x'] = [
                'filterable-fields' => $data->filterableFields,
                'default-filters' => array_map(
                    fn (array $entry) => ['field' => $entry[0], 'value' => $entry[1]],
                    $data->defaultFilters
                ),
            ];
        }

        return new OA\Parameter($properties);
    }

    /**
     * Build the description string for an order_by parameter.
     */
    private function buildOrderByDescription(array $orderableFields, array $defaultOrder): string
    {
        $description = 'Order results by specified field(s). '
            . 'Supports single field (order_by=field:direction) '
            . 'or multiple fields for tie-breaking (order_by[]=field1:direction&order_by[]=field2:direction).';

        if (! empty($orderableFields)) {
            $fieldList = implode('`, `', $orderableFields);
            $description .= "\n\n**Orderable fields:** `{$fieldList}`";
        }

        if (! empty($defaultOrder)) {
            $defaultList = implode('`, `', array_map(
                fn (array $entry) => "{$entry[0]}:{$entry[1]}",
                $defaultOrder
            ));
            $description .= "\n\n**Default order:** `{$defaultList}`";
        } else {
            $description .= "\n\n**Default order:** none";
        }

        return $description;
    }

    /**
     * Build the description string for a filter_by parameter.
     */
    private function buildFilterByDescription(array $filterableFields, array $defaultFilters): string
    {
        $description = 'Filter results by field values. '
            . 'Supports single filter (filter_by=field:value) '
            . 'or multiple filters (filter_by[]=field1:value&filter_by[]=field2:value).';

        if (! empty($filterableFields)) {
            $fieldList = implode('`, `', $filterableFields);
            $description .= "\n\n**Filterable fields:** `{$fieldList}`";
        }

        if (! empty($defaultFilters)) {
            $defaultList = implode('`, `', array_map(
                fn (array $entry) => "{$entry[0]}:{$entry[1]}",
                $defaultFilters
            ));
            $description .= "\n\n**Default filters:** `{$defaultList}`";
        } else {
            $description .= "\n\n**Default filters:** none";
        }

        return $description;
    }

    /**
     * Build an example value for the order_by parameter.
     */
    private function buildOrderByExample(array $defaultOrder, array $orderableFields): string
    {
        if (! empty($defaultOrder)) {
            return "{$defaultOrder[0][0]}:{$defaultOrder[0][1]}";
        }

        if (! empty($orderableFields)) {
            return "{$orderableFields[0]}:asc";
        }

        return 'created_at:desc';
    }

    /**
     * Build an example value for the filter_by parameter.
     */
    private function buildFilterByExample(array $defaultFilters, array $filterableFields): string
    {
        if (! empty($defaultFilters)) {
            return "{$defaultFilters[0][0]}:{$defaultFilters[0][1]}";
        }

        if (! empty($filterableFields)) {
            return "{$filterableFields[0]}:value";
        }

        return 'field:value';
    }
}
