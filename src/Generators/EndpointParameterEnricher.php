<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use OpenApi\Annotations as OA;
use OpenApi\Generator as OpenApiGen;
use Langsys\OpenApiDocsGenerator\Contracts\EndpointParameterResolver;
use Langsys\OpenApiDocsGenerator\Data\EndpointParameterData;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;

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

    private const SCHEMA_REF_PREFIX = '#/components/schemas/';

    /** Set per enrich() pass: `components.schemas` by name. */
    private array $schemas = [];

    public function __construct(
        private EndpointParameterResolver $resolver,
        private array $parameterNames = ['order_by', 'filter_by'],
        private bool $includeExtensions = true,
    ) {}

    /**
     * Enrich the OpenAPI document by replacing generic parameter refs
     * with endpoint-specific inline parameters for order_by/filter_by.
     *
     * @throws OpenApiDocsException when an enriched operation's orderable or filterable
     *                               fields name a property its resource schema lacks.
     */
    public function enrich(OA\OpenApi $openapi): void
    {
        if ($openapi->paths === OpenApiGen::UNDEFINED || ! is_array($openapi->paths)) {
            return;
        }

        $this->schemas = $this->indexSchemas($openapi);
        $unknownFields = [];

        foreach ($openapi->paths as $pathItem) {
            array_push($unknownFields, ...$this->enrichPathItem($pathItem));
        }

        if ($unknownFields !== []) {
            throw new OpenApiDocsException(
                "The orderable or filterable fields of these operations are not properties of the resource they list; correct the fields or the resource:\n- "
                . implode("\n- ", $unknownFields)
            );
        }
    }

    /**
     * Process all operations within a single path item.
     *
     * @return string[]  One line per operation whose fields its resource lacks.
     */
    private function enrichPathItem(OA\PathItem $pathItem): array
    {
        $unknownFields = [];

        foreach (self::OPERATION_METHODS as $method) {
            $operation = $pathItem->{$method};

            if ($operation === OpenApiGen::UNDEFINED) {
                continue;
            }

            if ($operation->parameters === OpenApiGen::UNDEFINED || ! is_array($operation->parameters)) {
                continue;
            }

            $line = $this->enrichOperation($operation, $pathItem->path, $method);

            if ($line !== null) {
                $unknownFields[] = $line;
            }
        }

        return $unknownFields;
    }

    /**
     * Enrich a single operation's parameters.
     *
     * @return string|null  The fields its resource schema lacks, when it was enriched.
     */
    private function enrichOperation(OA\Operation $operation, string $path, string $method): ?string
    {
        $schemaName = $this->inferSchemaName($operation);

        if ($schemaName === null) {
            return null;
        }

        $endpointPath = ltrim($path, '/');
        $data = $this->resolver->resolve($endpointPath, $schemaName . 'Resource');

        if ($data === null) {
            return null;
        }

        $properties = isset($this->schemas[$schemaName]) ? $this->propertiesOf($this->schemas[$schemaName]) : null;
        [$parameters, $enriched] = $this->replaceRefParameters($operation->parameters, $data, $properties);
        $operation->parameters = $parameters;

        if (! $enriched || $properties === null) {
            return null;
        }

        return $this->unknownFields($data, $properties, sprintf('%s %s, %s', strtoupper($method), $path, $schemaName));
    }

    /**
     * The orderable and filterable fields the resource schema has no property for, as
     * one line after $label, or null when it has them all.
     *
     * @param  array<string, OA\Property>  $properties
     */
    private function unknownFields(EndpointParameterData $data, array $properties, string $label): ?string
    {
        $missing = [];

        foreach (['orderable' => $data->orderableFields, 'filterable' => $data->filterableFields] as $kind => $fields) {
            $unknown = array_values(array_diff($fields, array_map('strval', array_keys($properties))));

            if (! empty($unknown)) {
                $missing[] = sprintf('%s `%s`', $kind, implode('`, `', $unknown));
            }
        }

        return empty($missing) ? null : $label . ': ' . implode('; ', $missing);
    }

    /**
     * Infer the resource's schema name from the operation's 200 response $ref.
     *
     * Looks for a JsonContent or MediaType schema ref in the 200 response,
     * extracts the schema name and strips response suffixes. The resolver is asked
     * for that name plus "Resource", the class the schema was built from.
     *
     * Example: "#/components/schemas/ProjectPaginatedResponse" -> "Project"
     */
    private function inferSchemaName(OA\Operation $operation): ?string
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

        return $baseName;
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
     * A list with no filterable fields can't filter (and has no default filters,
     * which are set on filterable fields), so its filter_by is left out.
     *
     * @param  OA\Parameter[]  $parameters
     * @param  array<string, OA\Property>|null  $properties  The resource schema's, null when unknown.
     * @return array{0: OA\Parameter[], 1: bool}  The parameters, and whether any was replaced or left out.
     */
    private function replaceRefParameters(array $parameters, EndpointParameterData $data, ?array $properties): array
    {
        $result = [];
        $enriched = false;

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
                if ($refName === 'filter_by' && empty($data->filterableFields)) {
                    $enriched = true;
                    continue;
                }

                $inlineParam = $this->buildInlineParameter($refName, $data, $properties);
                if ($inlineParam !== null) {
                    $result[] = $inlineParam;
                    $enriched = true;
                    continue;
                }
            }

            // Keep the original parameter if no replacement was made
            $result[] = $parameter;
        }

        return [$result, $enriched];
    }

    /**
     * Build an inline parameter to replace a generic $ref parameter.
     *
     * @param  array<string, OA\Property>|null  $properties
     */
    private function buildInlineParameter(string $parameterName, EndpointParameterData $data, ?array $properties): ?OA\Parameter
    {
        if ($parameterName === 'order_by') {
            return $this->buildOrderByParameter($data);
        }

        if ($parameterName === 'filter_by') {
            return $this->buildFilterByParameter($data, $properties);
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
     *
     * @param  array<string, OA\Property>|null  $properties
     */
    private function buildFilterByParameter(EndpointParameterData $data, ?array $properties): OA\Parameter
    {
        $description = $this->buildFilterByDescription(
            $data->filterableFields,
            $data->defaultFilters,
            $data->fieldTypes,
        );
        $example = $this->buildFilterByExample($data->defaultFilters, $data->filterableFields, $properties);

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
        }

        return $description;
    }

    /**
     * Build the description string for a filter_by parameter.
     *
     * @param  array<string>  $filterableFields
     * @param  array<array{0: string, 1: string}>  $defaultFilters
     * @param  array<string, array{type: string, nullable: bool}>  $fieldTypes
     */
    private function buildFilterByDescription(
        array $filterableFields,
        array $defaultFilters,
        array $fieldTypes = [],
    ): string {
        $description = "Filter results by field values.\n\n"
            . "Operators:\n"
            . "- `field:value`          equality (strict type match)\n"
            . "- `field:null`           field is null\n"
            . "- `field:!null`          field is not null\n"
            . "- `field:<op>:value`     comparison (op ∈ `>`, `<`, `>=`, `<=`) — numeric fields; date comparison also supported when value is `YYYY-MM-DD` (e.g. `2026-01-01`)\n\n"
            . 'Multiple filters: `filter_by[]=field1:value&filter_by[]=field2:value`';

        if (! empty($filterableFields)) {
            $fieldList = implode('`, `', $filterableFields);
            $description .= "\n\n**Filterable fields:** `{$fieldList}`";
        }

        if (! empty($fieldTypes)) {
            $nullableFields = array_values(array_filter(
                $filterableFields,
                fn (string $field) => ($fieldTypes[$field]['nullable'] ?? false) === true,
            ));

            $comparisonFields = array_values(array_filter(
                $filterableFields,
                fn (string $field) => in_array(
                    $fieldTypes[$field]['type'] ?? null,
                    ['int', 'float'],
                    true,
                ),
            ));

            if (! empty($nullableFields)) {
                $description .= "\n\n**Supports null check (`field:null` / `field:!null`):** `"
                    . implode('`, `', $nullableFields) . '`';
            }

            if (! empty($comparisonFields)) {
                $description .= "\n\n**Supports comparison (`>`, `<`, `>=`, `<=`):** `"
                    . implode('`, `', $comparisonFields) . '`';
            }
        }

        if (! empty($defaultFilters)) {
            $defaultList = implode('`, `', array_map(
                fn (array $entry) => "{$entry[0]}:{$entry[1]}",
                $defaultFilters
            ));
            $description .= "\n\n**Default filters:** `{$defaultList}`";
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
     * Build an example value for the filter_by parameter: the first default filter, else
     * the first filterable field the resource schema shows a value for.
     *
     * @param  array<string>  $filterableFields  Never empty: a list without any has no filter_by.
     * @param  array<string, OA\Property>|null  $properties
     */
    private function buildFilterByExample(array $defaultFilters, array $filterableFields, ?array $properties): string
    {
        if (! empty($defaultFilters)) {
            return "{$defaultFilters[0][0]}:{$defaultFilters[0][1]}";
        }

        foreach ($filterableFields as $field) {
            $value = isset($properties[$field]) ? $this->filterValue($properties[$field]) : null;

            if ($value !== null) {
                return "{$field}:{$value}";
            }
        }

        return "{$filterableFields[0]}:value";
    }

    /**
     * A property's value as a filter writes it: its scalar example, or the first case of
     * the enum schema it refers to (directly, or as a nullable `allOf`); booleans as
     * `true`/`false`. Null when it shows neither.
     */
    private function filterValue(OA\Schema $property): ?string
    {
        $value = $property->example;

        if ($value === OpenApiGen::UNDEFINED || ! is_scalar($value) || $value === '') {
            $enum = $this->referencedSchema($property)?->enum;
            $value = is_array($enum) && ! empty($enum) ? reset($enum) : null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    /**
     * The component schema a property refers to, by `$ref` or a one-member `allOf`.
     */
    private function referencedSchema(OA\Schema $property): ?OA\Schema
    {
        $ref = $property->ref;

        if ($ref === OpenApiGen::UNDEFINED && is_array($property->allOf) && count($property->allOf) === 1) {
            $ref = $property->allOf[array_key_first($property->allOf)]->ref;
        }

        if ($ref === OpenApiGen::UNDEFINED || ! is_string($ref) || ! str_starts_with($ref, self::SCHEMA_REF_PREFIX)) {
            return null;
        }

        return $this->schemas[substr($ref, strlen(self::SCHEMA_REF_PREFIX))] ?? null;
    }

    /**
     * A schema's properties by name, or null when it declares none of its own (an
     * `allOf` composition, say), so nothing can be said about its fields.
     *
     * @return array<string, OA\Property>|null
     */
    private function propertiesOf(OA\Schema $schema): ?array
    {
        if ($schema->properties === OpenApiGen::UNDEFINED || ! is_array($schema->properties)) {
            return null;
        }

        $properties = [];

        foreach ($schema->properties as $property) {
            if ($property instanceof OA\Property && $property->property !== OpenApiGen::UNDEFINED) {
                $properties[(string) $property->property] = $property;
            }
        }

        return $properties;
    }

    /**
     * `components.schemas` by name.
     *
     * @return array<string, OA\Schema>
     */
    private function indexSchemas(OA\OpenApi $openapi): array
    {
        if ($openapi->components === OpenApiGen::UNDEFINED
            || $openapi->components->schemas === OpenApiGen::UNDEFINED
            || ! is_array($openapi->components->schemas)) {
            return [];
        }

        $schemas = [];

        foreach ($openapi->components->schemas as $schema) {
            if ($schema instanceof OA\Schema && $schema->schema !== OpenApiGen::UNDEFINED) {
                $schemas[(string) $schema->schema] = $schema;
            }
        }

        return $schemas;
    }
}
