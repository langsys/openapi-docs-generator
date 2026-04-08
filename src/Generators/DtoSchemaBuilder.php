<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use Exception;
use Illuminate\Support\Collection;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Description;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Example;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\GroupedCollection;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Omit;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\OpenApiAttribute;
use OpenApi\Annotations as OA;
use OpenApi\Generator as OpenApiGenerator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Symfony\Component\Finder\Finder;

class DtoSchemaBuilder
{
    private const LARAVEL_DATA_OPTIONAL = 'Spatie\\LaravelData\\Optional';

    private const DATETIME_CLASSES = [
        'DateTime',
        'DateTimeImmutable',
        'DateTimeInterface',
        'Carbon\\Carbon',
        'Carbon\\CarbonImmutable',
        'Carbon\\CarbonInterface',
        'Illuminate\\Support\\Carbon',
    ];

    /** @var string[] */
    private array $dtoPaths;

    /**
     * @param string|string[] $dtoPaths Directories to scan for Data subclasses
     */
    public function __construct(
        string|array $dtoPaths,
        private ExampleGenerator $exampleGenerator,
        private array $paginationFields,
    ) {
        $this->dtoPaths = (array) $dtoPaths;
    }

    /**
     * Scan the DTO directory and build all Schema objects.
     *
     * @return OA\Schema[]
     */
    public function buildAll(): array
    {
        $schemas = [];

        foreach ($this->discoverDtoClasses() as $className) {
            $schema = $this->buildSchema($className);
            if ($schema) {
                $schemas[] = $schema;

                if ($this->isResource($className)) {
                    $schemas = array_merge($schemas, $this->buildResponseSchemas($className, $schema));
                }
            }
        }

        return $schemas;
    }

    /**
     * Build a single OA\Schema from a DTO class via reflection.
     */
    public function buildSchema(string $className): ?OA\Schema
    {
        $reflection = new ReflectionClass($className);
        $properties = [];
        $required = [];

        $isRequest = str_contains($className, 'Request');

        foreach ($this->getClassProperties($reflection) as $prop) {
            $meta = $this->extractPropertyMetadata($prop);
            if ($meta->omit) {
                continue;
            }

            $oaProperty = $this->buildProperty($meta);
            $properties[] = $oaProperty;

            if ($meta->required) {
                $required[] = $meta->name;
            }
        }

        if (empty($properties)) {
            return null;
        }

        $schemaName = $this->resolveSchemaName($className);

        $schemaProps = [
            'schema' => $schemaName,
            'type' => 'object',
            'properties' => $properties,
        ];

        // Only include required for request schemas (matching v1 behavior)
        if ($isRequest && !empty($required)) {
            $schemaProps['required'] = $required;
        }

        return new OA\Schema($schemaProps);
    }

    // -------------------------------------------------------------------------
    // Discovery
    // -------------------------------------------------------------------------

    /**
     * Scan configured directories and return fully qualified class names of Data subclasses.
     *
     * @return string[]
     */
    private function discoverDtoClasses(): array
    {
        $classes = [];
        $finder = new Finder();

        $existingPaths = array_filter($this->dtoPaths, 'is_dir');

        if (empty($existingPaths)) {
            return [];
        }

        foreach ($finder->files()->name('*.php')->in($existingPaths) as $file) {
            $className = $this->extractClassName($file->getRealPath());

            if ($className !== null && $this->isDataSubclass($className)) {
                $classes[] = $className;
            }
        }

        return $classes;
    }

    /**
     * Parse a PHP file to extract the fully qualified class name.
     */
    private function extractClassName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return null;
        }

        $namespace = null;
        $class = null;

        if (preg_match('/^\s*namespace\s+([^;]+)\s*;/m', $contents, $matches)) {
            $namespace = trim($matches[1]);
        }

        if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $contents, $matches)) {
            $class = $matches[1];
        }

        if ($class === null) {
            return null;
        }

        return $namespace !== null ? $namespace . '\\' . $class : $class;
    }

    /**
     * Determine whether a class extends Spatie\LaravelData\Data (directly or indirectly).
     */
    private function isDataSubclass(string $className): bool
    {
        try {
            $reflection = new ReflectionClass($className);
            $parent = $reflection->getParentClass();

            if (!$parent) {
                return false;
            }

            return $parent->getName() === 'Spatie\\LaravelData\\Data'
                || $this->isDataSubclass($parent->getName());
        } catch (Exception $e) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Property Reflection
    // -------------------------------------------------------------------------

    /**
     * Get the public properties of a class that should be reflected on.
     *
     * @return ReflectionProperty[]
     */
    private function getClassProperties(ReflectionClass $reflection): array
    {
        return $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
    }

    /**
     * Extract metadata from a single reflected property.
     */
    private function extractPropertyMetadata(ReflectionProperty $property): object
    {
        $type = $this->resolvePropertyType($property);
        $attributes = $this->parseAttributes($property->getAttributes());

        $typeName = $type->getName();
        // Normalize Illuminate\Support\Collection to array
        if ($typeName === 'Illuminate\\Support\\Collection') {
            $typeName = 'array';
        }

        $typeShortName = array_reverse(explode('\\', $type->getName()))[0];
        if ($typeShortName === 'Collection') {
            $typeShortName = 'array';
        }

        $defaultValue = $this->resolveDefaultValue($property);

        // v4 support: if no collectionOf from attributes and type is array/Collection,
        // try resolving from @var docblock
        $collectionOf = $attributes->collectionOf;
        if ($collectionOf === null && $typeName === 'array') {
            $collectionOf = $this->resolveCollectionOfFromDocBlock($property);
        }

        $hasDefault = $defaultValue !== null || $this->propertyHasDefault($property);

        return (object) [
            'name' => $property->getName(),
            'type' => $type,
            'typeName' => $typeName,
            'typeShortName' => $typeShortName,
            'omit' => $attributes->omit,
            'example' => $attributes->example,
            'exampleArguments' => $attributes->exampleArguments,
            'description' => $attributes->description,
            'collectionOf' => $collectionOf,
            'groupedCollection' => $attributes->groupedCollection,
            'defaultValue' => $defaultValue,
            'hasDefault' => $hasDefault,
            'required' => !$type->allowsNull() && !$this->propertyDeclaresLaravelDataOptional($property),
        ];
    }

    /**
     * Resolve the effective ReflectionType for a property, unwrapping union types.
     *
     * Unions that include Spatie\LaravelData\Optional are reduced to the remaining
     * type(s) — Optional is a sentinel, not a data type.
     */
    private function resolvePropertyType(ReflectionProperty $property): ReflectionType
    {
        $type = $property->getType();

        if (!$type instanceof ReflectionUnionType) {
            return $type;
        }

        $nonOptionalMembers = [];
        foreach ($type->getTypes() as $member) {
            if ($member instanceof ReflectionNamedType && $this->isLaravelDataOptionalType($member)) {
                continue;
            }
            $nonOptionalMembers[] = $member;
        }

        return $nonOptionalMembers[0] ?? $type->getTypes()[0];
    }

    /**
     * Whether the property type is a union that includes Laravel Data's Optional sentinel.
     */
    private function propertyDeclaresLaravelDataOptional(ReflectionProperty $property): bool
    {
        $type = $property->getType();
        if (!$type instanceof ReflectionUnionType) {
            return false;
        }

        foreach ($type->getTypes() as $member) {
            if ($member instanceof ReflectionNamedType && $this->isLaravelDataOptionalType($member)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a named type refers to Spatie\LaravelData\Optional.
     */
    private function isLaravelDataOptionalType(ReflectionNamedType $type): bool
    {
        $name = $type->getName();

        return $name === self::LARAVEL_DATA_OPTIONAL
            || (class_exists($name) && is_a($name, self::LARAVEL_DATA_OPTIONAL, true));
    }

    /**
     * Parse PHP attributes into a structured object with known attribute values.
     */
    private function parseAttributes(array $reflectionAttributes): object
    {
        $result = (object) [
            'omit' => false,
            'example' => null,
            'exampleArguments' => [],
            'description' => '',
            'collectionOf' => null,
            'groupedCollection' => null,
        ];

        foreach ($reflectionAttributes as $attr) {
            $instance = $attr->newInstance();

            if ($instance instanceof Omit) {
                $result->omit = true;
            } elseif ($instance instanceof Example) {
                $result->example = $instance->content;
                $result->exampleArguments = $instance->arguments;
            } elseif ($instance instanceof Description) {
                $result->description = $instance->content;
            } elseif ($instance instanceof GroupedCollection) {
                $result->groupedCollection = $instance->content;
            } elseif ($instance instanceof DataCollectionOf) {
                $result->collectionOf = $instance->class;
            }
        }

        return $result;
    }

    /**
     * Resolve the default value for a property from constructor params or property declaration.
     */
    private function resolveDefaultValue(ReflectionProperty $property): mixed
    {
        $class = $property->getDeclaringClass();
        $constructor = $class->getConstructor();

        if ($constructor) {
            foreach ($constructor->getParameters() as $parameter) {
                if ($parameter->getName() === $property->getName() && $parameter->isDefaultValueAvailable()) {
                    $defaultValue = $parameter->getDefaultValue();
                    return $this->normalizeEnumDefault($defaultValue, $parameter->getType());
                }
            }
        }

        if ($property->hasDefaultValue()) {
            $defaultValue = $property->getDefaultValue();
            return $this->normalizeEnumDefault($defaultValue, $property->getType());
        }

        return null;
    }

    /**
     * Check if a property has a declared default value (including null).
     */
    private function propertyHasDefault(ReflectionProperty $property): bool
    {
        $constructor = $property->getDeclaringClass()->getConstructor();

        if ($constructor) {
            foreach ($constructor->getParameters() as $parameter) {
                if ($parameter->getName() === $property->getName()) {
                    return $parameter->isDefaultValueAvailable();
                }
            }
        }

        return $property->hasDefaultValue();
    }

    /**
     * If the default value is a backed enum case, return its scalar value.
     */
    private function normalizeEnumDefault(mixed $defaultValue, ?ReflectionType $type): mixed
    {
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if ($member instanceof ReflectionNamedType && enum_exists($member->getName())) {
                    return $defaultValue?->value;
                }
            }
        }

        if ($type instanceof ReflectionNamedType && enum_exists($type->getName())) {
            return $defaultValue?->value;
        }

        return $defaultValue;
    }

    // -------------------------------------------------------------------------
    // OA\Property Building
    // -------------------------------------------------------------------------

    /**
     * Build a single OA\Property from extracted metadata.
     */
    private function buildProperty(object $meta): OA\Property
    {
        $typeName = $meta->typeName;
        $type = $meta->type;

        // Detect enum
        if (enum_exists($typeName)) {
            return $this->buildEnumProperty($meta);
        }

        // Detect DataCollection with #[DataCollectionOf]
        if (str_contains($typeName, 'DataCollection') && $meta->collectionOf !== null) {
            return $this->buildDataCollectionProperty($meta);
        }

        // Detect DateTime/Carbon types — treat as string with date-time format
        if ($this->isDateTimeType($typeName)) {
            return $this->buildDateTimeProperty($meta);
        }

        // Detect nested Data subclass (non-builtin, non-array, non-enum)
        if ($typeName !== 'array' && !$type->isBuiltin() && !enum_exists($typeName)) {
            return $this->buildNestedObjectProperty($meta);
        }

        // v4: array/Collection with collectionOf resolved from docblock
        if ($typeName === 'array' && $meta->collectionOf !== null) {
            return $this->buildDataCollectionProperty($meta);
        }

        // Detect grouped array (plain array with GroupedCollection attribute, no collectionOf)
        if ($meta->groupedCollection !== null && $typeName === 'array') {
            return $this->buildSimpleGroupedProperty($meta);
        }

        // Detect plain array
        if ($typeName === 'array') {
            return $this->buildArrayProperty($meta);
        }

        // Primitive types: string, int, bool, float
        return $this->buildPrimitiveProperty($meta);
    }

    /**
     * Check if a type name is a DateTime-related class.
     */
    private function isDateTimeType(string $typeName): bool
    {
        foreach (self::DATETIME_CLASSES as $dateTimeClass) {
            if ($typeName === $dateTimeClass || str_ends_with($typeName, '\\' . $dateTimeClass)) {
                return true;
            }
        }

        return is_a($typeName, \DateTimeInterface::class, true);
    }

    /**
     * Build a property for DateTime/Carbon types — serialized as ISO 8601 strings.
     */
    private function buildDateTimeProperty(object $meta): OA\Property
    {
        $example = $meta->example ?? '2024-01-15T10:30:00+00:00';

        $props = [
            'property' => $meta->name,
            'type' => 'string',
            'format' => 'date-time',
            'example' => $example,
        ];

        if ($meta->description) {
            $props['description'] = $meta->description;
        }

        if ($meta->defaultValue !== null) {
            $props['default'] = $meta->defaultValue;
        }

        if (!$meta->required) {
            $props['nullable'] = true;
        }

        return new OA\Property($props);
    }

    /**
     * Build a property for an enum type.
     */
    private function buildEnumProperty(object $meta): OA\Property
    {
        $enumClass = $meta->typeName;
        $cases = $enumClass::cases();
        $enumValues = array_column($cases, 'value');

        // Determine the underlying enum type (string or integer)
        $backingType = 'string';
        if (!empty($cases) && is_int($cases[0]->value)) {
            $backingType = 'integer';
        }

        // Example: use explicit #[Example] if valid, otherwise pick random case
        $example = $meta->example;
        if ($example === null || !in_array($example, $enumValues)) {
            $example = $enumValues[array_rand($enumValues)];
        }

        $props = [
            'property' => $meta->name,
            'type' => $backingType,
            'enum' => $enumValues,
            'example' => $example,
        ];

        if ($meta->description) {
            $props['description'] = $meta->description;
        }

        if ($meta->defaultValue !== null) {
            $props['default'] = $meta->defaultValue;
        } elseif ($meta->hasDefault && $meta->defaultValue === null) {
            $props['default'] = null;
        }

        if (!$meta->required) {
            $props['nullable'] = true;
        }

        return new OA\Property($props);
    }

    /**
     * Build a property for a DataCollection with #[DataCollectionOf].
     * May also be a GroupedCollection if #[GroupedCollection] is present.
     */
    private function buildDataCollectionProperty(object $meta): OA\Property
    {
        $refClass = $meta->collectionOf;
        $refSchemaName = $this->resolveSchemaName($refClass);

        // If grouped collection: type object with additionalProperties containing array of $ref items
        if ($meta->groupedCollection !== null) {
            return $this->buildGroupedCollectionProperty($meta, $refSchemaName);
        }

        // Plain DataCollection: type array with items.$ref wrapped in allOf
        $props = [
            'property' => $meta->name,
            'type' => 'array',
            'items' => new OA\Items([
                'allOf' => [
                    new OA\Schema(['ref' => '#/components/schemas/' . $refSchemaName]),
                ],
            ]),
        ];

        if ($meta->description) {
            $props['description'] = $meta->description;
        }

        return new OA\Property($props);
    }

    /**
     * Build a property for a grouped collection (dictionary/map of arrays of schema refs).
     */
    private function buildGroupedCollectionProperty(object $meta, string $refSchemaName): OA\Property
    {
        $props = [
            'property' => $meta->name,
            'type' => 'object',
            'additionalProperties' => new OA\AdditionalProperties([
                'type' => 'array',
                'items' => new OA\Items([
                    'ref' => '#/components/schemas/' . $refSchemaName,
                ]),
            ]),
        ];

        if ($meta->description) {
            $props['description'] = $meta->description;
        }

        return new OA\Property($props);
    }

    /**
     * Build a property for a nested Data object (non-array, non-builtin, non-enum).
     */
    private function buildNestedObjectProperty(object $meta): OA\Property
    {
        $refSchemaName = $this->resolveSchemaName($meta->typeName);

        $props = [
            'property' => $meta->name,
            'allOf' => [
                new OA\Schema(['ref' => '#/components/schemas/' . $refSchemaName]),
            ],
        ];

        if ($meta->description) {
            $props['description'] = $meta->description;
        }

        return new OA\Property($props);
    }

    /**
     * Build a property for a simple grouped array (array with GroupedCollection attribute, no DataCollectionOf).
     * The v1 behavior was: type object with example { dictionaryKey: content }.
     */
    private function buildSimpleGroupedProperty(object $meta): OA\Property
    {
        $example = $this->generateExample($meta);

        $props = [
            'property' => $meta->name,
            'type' => 'object',
            'example' => [$meta->groupedCollection => $example],
        ];

        if ($meta->description) {
            $props['description'] = $meta->description;
        }

        return new OA\Property($props);
    }

    /**
     * Build a property for a plain array type.
     */
    private function buildArrayProperty(object $meta): OA\Property
    {
        $example = $this->generateExample($meta);

        $props = [
            'property' => $meta->name,
            'type' => 'array',
            'items' => new OA\Items([
                'type' => gettype($example) === 'integer' ? 'integer' : 'string',
                'example' => $example,
            ]),
        ];

        if ($meta->description) {
            $props['description'] = $meta->description;
        }

        return new OA\Property($props);
    }

    /**
     * Build a property for primitive types (string, int, bool, float).
     */
    private function buildPrimitiveProperty(object $meta): OA\Property
    {
        $openApiType = $this->mapPhpTypeToOpenApi($meta->typeName);
        $example = $this->generateExample($meta);

        // For bool, default to true if example is empty
        if ($openApiType === 'boolean' && ($example === '' || $example === null)) {
            $example = is_bool($meta->example) ? $meta->example : true;
        }

        // For integer, cast
        if ($openApiType === 'integer') {
            $example = (int) $example;
        }

        $props = [
            'property' => $meta->name,
            'type' => $openApiType,
            'example' => $example,
        ];

        if ($openApiType === 'number') {
            $props['format'] = 'float';
        }

        if ($meta->description) {
            $props['description'] = $meta->description;
        }

        if ($meta->defaultValue !== null) {
            $props['default'] = $meta->defaultValue;
        }

        return new OA\Property($props);
    }

    // -------------------------------------------------------------------------
    // Example Generation
    // -------------------------------------------------------------------------

    /**
     * Generate an example value for a property using the ExampleGenerator.
     */
    private function generateExample(object $meta): mixed
    {
        $example = $meta->example;
        $arguments = $meta->exampleArguments;

        // If there is an explicit example that is not a faker function reference, use it directly
        if ($example !== null && !str_starts_with((string) $example, ExampleGenerator::FAKER_FUNCTION_PREFIX)) {
            return $example;
        }

        // Use ExampleGenerator (faker-based)
        $exampleFunction = $example ?? $meta->name;
        $exampleFunction = (string) $exampleFunction;
        $arguments = [...$arguments, 'type' => $meta->typeName];

        if (method_exists($this->exampleGenerator, $exampleFunction)) {
            return $this->exampleGenerator->$exampleFunction(...array_values($arguments));
        }

        return $this->exampleGenerator->$exampleFunction($arguments);
    }

    // -------------------------------------------------------------------------
    // Response Schema Generation
    // -------------------------------------------------------------------------

    /**
     * Check if a class name indicates a Resource DTO (name ends with "Resource").
     */
    private function isResource(string $className): bool
    {
        return str_ends_with(class_basename($className), 'Resource');
    }

    /**
     * Resolve the schema name for a DTO class.
     * For Resource classes, strip the "Resource" suffix.
     */
    private function resolveSchemaName(string $className): string
    {
        $baseName = class_basename($className);
        if (str_contains($baseName, 'Resource')) {
            return str_replace('Resource', '', $baseName);
        }

        return $baseName;
    }

    /**
     * Build Response, PaginatedResponse, and ListResponse schemas for a Resource DTO.
     *
     * @return OA\Schema[]
     */
    private function buildResponseSchemas(string $className, OA\Schema $resourceSchema): array
    {
        $baseName = $this->resolveSchemaName($className);

        return [
            $this->buildSingleResponseSchema($baseName, $resourceSchema),
            $this->buildPaginatedResponseSchema($baseName, $resourceSchema),
            $this->buildListResponseSchema($baseName, $resourceSchema),
        ];
    }

    /**
     * Build {Name}Response schema: { status: bool, data: {Resource} }
     */
    private function buildSingleResponseSchema(string $baseName, OA\Schema $resourceSchema): OA\Schema
    {
        return new OA\Schema([
            'schema' => $baseName . 'Response',
            'type' => 'object',
            'properties' => [
                new OA\Property([
                    'property' => 'status',
                    'type' => 'boolean',
                    'description' => 'Response status',
                    'example' => true,
                ]),
                new OA\Property([
                    'property' => 'data',
                    'description' => 'Response payload',
                    'type' => 'object',
                    'allOf' => [
                        new OA\Schema(['ref' => '#/components/schemas/' . $baseName]),
                    ],
                ]),
            ],
        ]);
    }

    /**
     * Build {Name}PaginatedResponse schema: { pagination fields..., data: [{Resource}] }
     */
    private function buildPaginatedResponseSchema(string $baseName, OA\Schema $resourceSchema): OA\Schema
    {
        $properties = [];

        foreach ($this->paginationFields as $field) {
            $fieldType = $this->mapPhpTypeToOpenApi($field['type'] ?? 'string');
            $properties[] = new OA\Property([
                'property' => $field['name'],
                'type' => $fieldType,
                'description' => $field['description'] ?? '',
                'example' => $field['content'] ?? null,
            ]);
        }

        // data: array of resource refs
        $properties[] = new OA\Property([
            'property' => 'data',
            'type' => 'array',
            'description' => 'List of items',
            'items' => new OA\Items([
                'allOf' => [
                    new OA\Schema(['ref' => '#/components/schemas/' . $baseName]),
                ],
            ]),
        ]);

        return new OA\Schema([
            'schema' => $baseName . 'PaginatedResponse',
            'type' => 'object',
            'properties' => $properties,
        ]);
    }

    /**
     * Build {Name}ListResponse schema: { status: bool, data: [{Resource}] }
     */
    private function buildListResponseSchema(string $baseName, OA\Schema $resourceSchema): OA\Schema
    {
        return new OA\Schema([
            'schema' => $baseName . 'ListResponse',
            'type' => 'object',
            'properties' => [
                new OA\Property([
                    'property' => 'status',
                    'type' => 'boolean',
                    'description' => 'Response status',
                    'example' => true,
                ]),
                new OA\Property([
                    'property' => 'data',
                    'type' => 'array',
                    'description' => 'List of items',
                    'items' => new OA\Items([
                        'allOf' => [
                            new OA\Schema(['ref' => '#/components/schemas/' . $baseName]),
                        ],
                    ]),
                ]),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Docblock Parsing (Laravel Data v4 support)
    // -------------------------------------------------------------------------

    /**
     * Resolve a collection-of class from a property's @var docblock annotation.
     *
     * Supports v4 patterns: `ClassName[]`, `array<key, ClassName>`, `Collection<key, ClassName>`.
     * Returns the FQCN only if it's a Data subclass, otherwise null.
     */
    private function resolveCollectionOfFromDocBlock(ReflectionProperty $property): ?string
    {
        $docComment = $property->getDocComment();
        if ($docComment === false) {
            return null;
        }

        // Match @var patterns: ClassName[], array<key, ClassName>, Collection<key, ClassName>
        $patterns = [
            '/@var\s+([\w\\\\]+)\[\]/',                          // ClassName[]
            '/@var\s+(?:array|Collection)<\s*\w+\s*,\s*([\w\\\\]+)\s*>/', // array<key, ClassName> or Collection<key, ClassName>
        ];

        $className = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $docComment, $matches)) {
                $className = $matches[1];
                break;
            }
        }

        if ($className === null) {
            return null;
        }

        $fqcn = $this->resolveClassFqcn($className, $property->getDeclaringClass());
        if ($fqcn === null) {
            return null;
        }

        return $this->isDataSubclass($fqcn) ? $fqcn : null;
    }

    /**
     * Resolve a short class name to its FQCN using the declaring class context.
     *
     * Checks: already fully qualified, same namespace, use statements in source file.
     */
    private function resolveClassFqcn(string $shortName, ReflectionClass $declaringClass): ?string
    {
        // Already a FQCN
        if (class_exists($shortName)) {
            return $shortName;
        }

        // Try same namespace as declaring class
        $namespace = $declaringClass->getNamespaceName();
        if ($namespace) {
            $candidate = $namespace . '\\' . $shortName;
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        // Parse use statements from the source file
        $fileName = $declaringClass->getFileName();
        if ($fileName === false) {
            return null;
        }

        $contents = file_get_contents($fileName);
        if ($contents === false) {
            return null;
        }

        // Match use statements: `use Foo\Bar\ClassName;` or `use Foo\Bar\ClassName as Alias;`
        if (preg_match_all('/^\s*use\s+([\w\\\\]+?)(?:\s+as\s+(\w+))?\s*;/m', $contents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fqcn = $match[1];
                $alias = $match[2] ?? class_basename($fqcn);
                if ($alias === $shortName && class_exists($fqcn)) {
                    return $fqcn;
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Map a PHP type name to an OpenAPI type string.
     */
    private function mapPhpTypeToOpenApi(string $phpType): string
    {
        return match ($phpType) {
            'int', 'integer' => 'integer',
            'float', 'double' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            default => 'string',
        };
    }
}
