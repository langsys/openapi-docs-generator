<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use DateTime;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Langsys\OpenApiDocsGenerator\Data\ErrorDefinition;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Description;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\EnvelopeField;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Example;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\GroupedCollection;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\ItemType;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Omit;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\OneOfItemsFrom;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\OpenApiAttribute;
use OpenApi\Annotations as OA;
use OpenApi\Generator as OpenApiGenerator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionEnumBackedCase;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\FromRouteParameter;
use Spatie\LaravelData\Attributes\FromRouteParameterProperty;
use Spatie\LaravelData\Attributes\InjectsPropertyValue;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Url;
use Spatie\LaravelData\Attributes\Validation\Uuid;
use Spatie\LaravelData\Support\DataConfig;
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

    /**
     * laravel-data validation attributes whose rule pins a string to an OpenAPI format
     * (#[DateFormat] is read by DATE_FORMATS). The rule applies to the property's value
     * itself (laravel-data has no attribute for an array's elements), so only a string
     * schema takes the format.
     */
    private const VALIDATION_FORMATS = [
        Uuid::class => 'uuid',
        Date::class => 'date',
        Email::class => 'email',
        Url::class => 'uri',
    ];

    /**
     * #[DateFormat] formats that are an OpenAPI format: a calendar date, or an RFC 3339
     * date-time (DATE_RFC3339 is DATE_ATOM). `c` is not one: PHP cannot parse with it, so
     * `date_format:c` accepts nothing.
     */
    private const DATE_FORMATS = [
        'Y-m-d' => 'date',
        DATE_ATOM => 'date-time',
        DATE_RFC3339_EXTENDED => 'date-time',
    ];

    /** @var string[] */
    private array $dtoPaths;

    /** @var array<string, array<int, object>> */
    private array $oneOfGroups = [];

    /**
     * Default `errors` config other than the envelope field names, which
     * ErrorEnvelope owns (`response_fields`, `error_fields`).
     */
    public const DEFAULT_ERROR_CONFIG = [
        'base_class' => null,
        'paths' => null,
        'code_schema' => 'ErrorCode',
        'case_suffix' => 'Error',
    ];

    /** @var array<string, mixed> Merged `errors` config (see DEFAULT_ERROR_CONFIG). */
    private array $errorConfig;

    /** The error response shape; shared with OperationErrorAttacher via getErrorEnvelope(). */
    private ErrorEnvelope $errorEnvelope;

    /** The error class contract: discovery by base class, identity from constants. */
    private ErrorContract $errorContract;

    /** @var ErrorDefinition[] Populated by buildAll(), keyed by class name. */
    private array $errorDefinitions = [];

    /**
     * @param string|string[] $dtoPaths Directories to scan for Data subclasses
     * @param array<string, mixed> $errorConfig The documentation set's `errors` block
     */
    public function __construct(
        string|array $dtoPaths,
        private ExampleGenerator $exampleGenerator,
        private array $paginationFields,
        array $errorConfig = [],
    ) {
        $this->dtoPaths = (array) $dtoPaths;
        $this->errorConfig = array_replace(self::DEFAULT_ERROR_CONFIG, $errorConfig);
        $this->errorEnvelope = new ErrorEnvelope($errorConfig);
        $this->errorContract = new ErrorContract($this->errorConfig['base_class']);
    }

    /**
     * Errors discovered by the last buildAll() call.
     *
     * @return ErrorDefinition[]
     */
    public function getErrorDefinitions(): array
    {
        return array_values($this->errorDefinitions);
    }

    /**
     * Name of the error-code enum schema (`errors.code_schema`, default "ErrorCode").
     */
    public function getErrorCodeSchemaName(): string
    {
        return (string) $this->errorConfig['code_schema'];
    }

    /**
     * The error response shape this builder emits, so attached responses can be
     * built from the same instance and always match the schemas they reference.
     */
    public function getErrorEnvelope(): ErrorEnvelope
    {
        return $this->errorEnvelope;
    }

    /**
     * The error class contract this builder discovers and reads errors with, so the
     * attacher can say precisely why a declared class is not a documented error.
     */
    public function getErrorContract(): ErrorContract
    {
        return $this->errorContract;
    }

    /**
     * Scan the DTO directory and build all Schema objects.
     *
     * @return OA\Schema[]
     */
    public function buildAll(): array
    {
        $schemas = [];
        ['classes' => $classes, 'catalogs' => $catalogs] = $this->discoverTypes();
        $this->oneOfGroups = $this->discoverOneOfGroups($classes);
        $this->errorDefinitions = [];

        foreach ($classes as $className) {
            if ($this->isErrorClass($className)) {
                $schemas = array_merge($schemas, $this->buildErrorSchemas($className));
                continue;
            }

            $schema = $this->buildSchema($className);
            if ($schema) {
                $schemas[] = $schema;

                if ($this->isResource($className)) {
                    $schemas = array_merge($schemas, $this->buildResponseSchemas($className, $schema));
                }
            }
        }

        foreach ($catalogs as $catalog) {
            foreach ($this->errorContract->casesOf($catalog) as $case) {
                $schemas = array_merge($schemas, $this->buildErrorCaseSchemas($catalog, $case));
            }
        }

        $schemas = array_merge($schemas, $this->buildOneOfItemSchemas());

        // The error-code enum is not built here: OpenApiGenerator builds it with
        // buildErrorCodeSchema() once it knows which errors the operations reference.

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
        $isError = $this->isErrorClass($className);

        foreach ($this->getClassProperties($reflection) as $prop) {
            $meta = $this->extractPropertyMetadata($prop);
            if ($meta->omit) {
                continue;
            }

            // Envelope fields of an error DTO live on the {Name}Response envelope, not in details.
            if ($isError && $meta->envelopeField) {
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

        $schemaName = $isError ? class_basename($className) : $this->resolveSchemaName($className);

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
     * Scan the configured directories for the types this builder documents: Spatie Data
     * subclasses, and the error catalogs whose cases are errors.
     *
     * @return array{classes: array<int, string>, catalogs: array<int, string>}
     */
    private function discoverTypes(): array
    {
        $classes = [];
        $catalogs = [];
        $finder = new Finder();

        $paths = array_unique(array_merge($this->dtoPaths, (array) ($this->errorConfig['paths'] ?? [])));
        $existingPaths = array_filter($paths, 'is_dir');

        if (empty($existingPaths)) {
            return ['classes' => [], 'catalogs' => []];
        }

        foreach ($finder->files()->name('*.php')->in($existingPaths) as $file) {
            $name = $this->extractClassName($file->getRealPath());

            if ($name === null || (! class_exists($name) && ! enum_exists($name))) {
                continue;
            }

            if (enum_exists($name)) {
                if ($this->errorContract->isErrorCatalog($name)) {
                    $catalogs[] = $name;
                }

                continue;
            }

            if (! (new ReflectionClass($name))->isAbstract() && $this->isDataSubclass($name)) {
                $classes[] = $name;
            }
        }

        return ['classes' => $classes, 'catalogs' => $catalogs];
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

        if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|enum)\s+(\w+)/m', $contents, $matches)) {
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
     *
     * @param  ReflectionNamedType|null  $memberType  One member of a union-typed property to
     *                                                describe instead of its resolved type.
     */
    private function extractPropertyMetadata(ReflectionProperty $property, ?ReflectionNamedType $memberType = null): object
    {
        $type = $memberType ?? $this->resolvePropertyType($property);
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

        if ($memberType === null && ! $attributes->omit && is_array($attributes->example)) {
            $this->assertArrayExampleFits($property, $collectionOf, $attributes);
        }

        $hasDefault = $defaultValue !== null || $this->propertyHasDefault($property);
        // The declared type, not the member a union resolves to: `string|array|null` allows null.
        $nullable = ($memberType ?? $property->getType())->allowsNull();

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
            'oneOfItemsFrom' => $attributes->oneOfItemsFrom,
            'envelopeField' => $attributes->envelopeField,
            'format' => $attributes->format,
            'dateFormats' => $attributes->dateFormats,
            'exactDateFormat' => $attributes->exactDateFormat,
            'docComment' => $property->getDocComment() ?: null,
            'defaultValue' => $defaultValue,
            'hasDefault' => $hasDefault,
            'nullable' => $nullable,
            'required' => !$nullable && !$this->propertyDeclaresLaravelDataOptional($property) && !$hasDefault,
        ];
    }

    /**
     * An array #[Example] is the example of a property that takes an array, so it must
     * have that array's shape: keys for a string map (`@var array<string, T>`, an
     * object), none for a list. On a property that takes no array it would contradict
     * the schema, and on a collection of Data or #[OneOfItemsFrom] variants the item
     * schemas document the items, so generation fails instead.
     *
     * @throws OpenApiDocsException
     */
    private function assertArrayExampleFits(ReflectionProperty $property, ?string $collectionOf, object $attributes): void
    {
        $example = $attributes->example;
        $where = sprintf('#[Example] on %s::$%s', $property->getDeclaringClass()->getName(), $property->getName());

        if ($collectionOf !== null || $attributes->oneOfItemsFrom !== null) {
            throw new OpenApiDocsException(sprintf(
                '%s is an array, but the property is a collection of %s, whose schema documents its items; leave the example out.',
                $where,
                $collectionOf ?? sprintf("the #[OneOfItemsFrom('%s')] variants", $attributes->oneOfItemsFrom),
            ));
        }

        if (! $this->takesArray($property)) {
            throw new OpenApiDocsException(sprintf(
                '%s is an array, which only a property that takes an array can have (a list, or a string map declared `@var array<string, T>`); give it a scalar example.',
                $where,
            ));
        }

        if ($example === [] || $attributes->groupedCollection !== null) {
            return;
        }

        $isMap = $this->resolveStringMapValueFromDocBlock($property->getDocComment() ?: null) !== null;

        if ($isMap && array_is_list($example)) {
            throw new OpenApiDocsException(sprintf(
                '%s is a list, but the property is a string map (`@var array<string, T>`), documented as an object; give the example keys.',
                $where,
            ));
        }

        if (! $isMap && ! array_is_list($example)) {
            throw new OpenApiDocsException(sprintf(
                '%s has keys, but the property is documented as a list; declare it `@var array<string, T>` to document an object.',
                $where,
            ));
        }
    }

    /**
     * Whether the property's declared type, or a member of its union, is `array` or a
     * Collection, whatever member the union resolves to.
     */
    private function takesArray(ReflectionProperty $property): bool
    {
        $type = $property->getType();

        foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : [$type] as $member) {
            if ($member instanceof ReflectionNamedType
                && in_array($member->getName(), ['array', Collection::class], true)) {
                return true;
            }
        }

        return false;
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
            'oneOfItemsFrom' => null,
            'envelopeField' => false,
            'format' => null,
            'dateFormats' => [],
            'exactDateFormat' => false,
        ];
        $dateFormat = null;

        foreach ($reflectionAttributes as $attr) {
            $instance = $attr->newInstance();

            if ($instance instanceof Omit) {
                $result->omit = true;
            } elseif ($instance instanceof EnvelopeField) {
                $result->envelopeField = true;
            } elseif ($instance instanceof Example) {
                $result->example = $instance->content;
                $result->exampleArguments = $instance->arguments;
            } elseif ($instance instanceof Description) {
                $result->description = $instance->content;
            } elseif ($instance instanceof GroupedCollection) {
                $result->groupedCollection = $instance->content;
            } elseif ($instance instanceof OneOfItemsFrom) {
                $result->oneOfItemsFrom = $instance->group;
            } elseif ($instance instanceof DataCollectionOf) {
                $result->collectionOf = $instance->class;
            } elseif ($instance instanceof DateFormat) {
                $dateFormat = $instance;
            } elseif (($format = $this->validationFormat($instance)) !== null) {
                $result->format = $format;
            }
        }

        // #[DateFormat] is the exact rule, so it decides over #[Date] whichever comes
        // first, also when its formats pin none. `dateFormats` are the PHP formats an
        // example has to match.
        if ($dateFormat !== null) {
            [$result->format, $result->dateFormats] = $this->readDateFormat($dateFormat);
            $result->exactDateFormat = true;
        } elseif ($result->format === 'date') {
            $result->dateFormats = ['Y-m-d'];
        }

        return $result;
    }

    /**
     * The example, or, when it was generated (from the name or an #[Example(':faker')]
     * reference) and does not fit the property's format, one Faker makes that does. A
     * literal #[Example] is used as written.
     */
    private function fitExample(mixed $example, object $meta): mixed
    {
        $generated = $meta->example === null || $this->isFakerReference($meta->example);

        return $meta->format !== null && $generated && ! $this->fitsFormat($example, $meta)
            ? $this->formatExample($meta)
            : $example;
    }

    /**
     * Whether the property's type, or a member of its union, is a builtin documented as a
     * string schema (`string`, and `mixed` or `object`, which map to one): the schemas a
     * format goes on.
     */
    private function allowsString(ReflectionProperty $property): bool
    {
        $type = $property->getType();

        foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : [$type] as $member) {
            if ($member instanceof ReflectionNamedType
                && $member->isBuiltin()
                && $member->getName() !== 'null'
                && $this->mapPhpTypeToOpenApi($member->getName()) === 'string') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a value is a valid string of the given format (one of VALIDATION_FORMATS).
     */
    private function fitsFormat(mixed $value, object $meta): bool
    {
        return is_string($value) && match ($meta->format) {
            'uuid' => Str::isUuid($value),
            'date', 'date-time' => $this->fitsDateFormats($value, $meta->dateFormats),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'uri' => filter_var($value, FILTER_VALIDATE_URL) !== false,
        };
    }

    /**
     * Whether a value passes `date_format` with any of the PHP formats, checked as
     * Laravel checks it: parsed and formatted back unchanged.
     *
     * @param  array<int, string>  $dateFormats
     */
    private function fitsDateFormats(string $value, array $dateFormats): bool
    {
        foreach ($dateFormats as $dateFormat) {
            $date = DateTime::createFromFormat('!' . $dateFormat, $value);

            if ($date !== false && $date->format($dateFormat) === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * A Faker example of a string in the property's format; a date in the first of its
     * PHP formats. Faker is called directly: an app's custom example functions can share
     * a Faker function's name (the default config's `date` makes date-times).
     */
    private function formatExample(object $meta): string
    {
        $faker = fake();

        return match ($meta->format) {
            'uuid' => $faker->uuid(),
            'date', 'date-time' => $faker->dateTime()->format($meta->dateFormats[0]),
            'email' => $faker->safeEmail(),
            'uri' => $faker->url(),
        };
    }

    /**
     * The format a laravel-data validation attribute (or a subclass of one) pins a string
     * to, or null when it pins none. #[DateFormat] is read by readDateFormat().
     */
    private function validationFormat(object $attribute): ?string
    {
        foreach (self::VALIDATION_FORMATS as $class => $format) {
            if ($attribute instanceof $class) {
                return $format;
            }
        }

        return null;
    }

    /**
     * The format a #[DateFormat] pins a string to, the one its PHP formats all map to in
     * DATE_FORMATS, with those PHP formats; or [null, []] when any of them maps to none
     * (or is resolved at runtime) or they disagree. Its formats are a list in recent
     * laravel-data versions and a single string in older ones.
     *
     * @return array{0: ?string, 1: array<int, string>}
     */
    private function readDateFormat(DateFormat $attribute): array
    {
        $dateFormats = Arr::flatten(Arr::wrap($attribute->parameters()[0] ?? []));
        $formats = array_unique(array_map(
            static fn (mixed $format): ?string => is_string($format) ? (self::DATE_FORMATS[$format] ?? null) : null,
            $dateFormats,
        ));

        return count($formats) === 1 && reset($formats) !== null ? [reset($formats), $dateFormats] : [null, []];
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
     * Build a single OA\Property from extracted metadata. Whatever its shape, a property
     * whose type allows null is `nullable`.
     */
    private function buildProperty(object $meta): OA\Property
    {
        $property = $this->buildTypedProperty($meta);

        if ($meta->nullable) {
            $property->nullable = true;
        }

        return $property;
    }

    /**
     * The OA\Property for the property's type.
     */
    private function buildTypedProperty(object $meta): OA\Property
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

        // Detect array/Collection items described by a named oneOf group
        if ($typeName === 'array' && $meta->oneOfItemsFrom !== null) {
            return $this->buildOneOfItemsProperty($meta);
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
     * Build a property for a plain array type: an object for a string-keyed map
     * (`@var array<string, T>`), otherwise a list. An array #[Example] is the property's
     * example, and a list's items take its first element; otherwise the items get a
     * scalar example, and an object none.
     */
    private function buildArrayProperty(object $meta): OA\Property
    {
        $arrayExample = is_array($meta->example) ? $meta->example : null;

        $valueSchema = $this->resolveStringMapValueFromDocBlock($meta->docComment ?? null);
        if ($valueSchema !== null) {
            $props = [
                'property' => $meta->name,
                'type' => 'object',
            ];
            if ($valueSchema !== []) { // array<string, mixed>: a free-form object
                $props['additionalProperties'] = new OA\AdditionalProperties($valueSchema);
            }
            if ($arrayExample !== null) {
                $props['example'] = $arrayExample;
            }
            if ($meta->description) {
                $props['description'] = $meta->description;
            }

            return new OA\Property($props);
        }

        if ($arrayExample === null) {
            $example = $this->generateExample($meta);
        } else {
            $example = $arrayExample === [] ? null : $arrayExample[array_key_first($arrayExample)];
        }

        $items = [];
        if (($itemType = $this->itemType($example)) !== null) {
            $items['type'] = $itemType;
        }
        if ($example !== null) {
            $items['example'] = $example;
        }

        $props = [
            'property' => $meta->name,
            'type' => 'array',
            'items' => new OA\Items($items),
        ];

        if ($arrayExample !== null) {
            $props['example'] = $arrayExample;
        }

        if ($meta->description) {
            $props['description'] = $meta->description;
        }

        return new OA\Property($props);
    }

    /**
     * The type of a plain array's items, read from their example: a keyed array is an
     * object, a list leaves the type unstated (an `array` item schema would need items of
     * its own), and a string or no example is a string.
     */
    private function itemType(mixed $example): ?string
    {
        return match (true) {
            is_int($example) => 'integer',
            is_float($example) => 'number',
            is_bool($example) => 'boolean',
            is_array($example) => array_is_list($example) ? null : 'object',
            default => 'string',
        };
    }

    /**
     * Build an array property whose items can be one of several DTO-backed wrapper schemas.
     */
    private function buildOneOfItemsProperty(object $meta): OA\Property
    {
        $items = $this->oneOfGroups[$meta->oneOfItemsFrom] ?? [];

        $props = [
            'property' => $meta->name,
            'type' => 'array',
            'items' => new OA\Items([
                'oneOf' => array_map(
                    static fn (object $item): OA\Schema => new OA\Schema([
                        'ref' => '#/components/schemas/' . $item->itemSchemaName,
                    ]),
                    $items,
                ),
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

        if ($openApiType === 'string') {
            $example = $this->fitExample($example, $meta);
        }

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
        } elseif ($openApiType === 'string' && $meta->format !== null) {
            $props['format'] = $meta->format;
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
    // Query Parameters (see #[QueryParameters])
    // -------------------------------------------------------------------------

    /**
     * One `in: query` parameter per public property of a Data class that is read from the
     * request, named by the property's input name, so the documented name is the one the
     * request is read with. A dotted input name reads nested input, so it is written the
     * way a query string nests it: `filter.search` is `filter[search]`.
     *
     * Inherited properties come first, ancestors before descendants, so the parameters a
     * base DTO shares sit in the same place on every operation. A parameter is required
     * only when its property is non-nullable, has no default and is not Optional. The
     * schema is the property's type mapped as in a DTO schema, with each member of a
     * union under `oneOf`. Description and example come from #[Description] and
     * #[Example]; generated examples are left out, so a parameter shows an example only
     * when its DTO declares one.
     *
     * A #[Computed] property, and one laravel-data fills from elsewhere (a route
     * parameter, the authenticated user, the container) over whatever the request sends,
     * is not request input and is left out. One that yields to a value in the request
     * (`replaceWhenPresentInPayload: false`) is documented, and never required.
     *
     * @return array<int, OA\Parameter>
     */
    public function buildQueryParameters(string $className): array
    {
        $reflection = new ReflectionClass($className);
        $inputNames = $this->inputNames($className);
        $parameters = [];

        foreach ($this->inheritedFirst($reflection) as $prop) {
            if ($prop->isStatic() || $this->firstAttribute($prop, Computed::class) !== null) {
                continue;
            }

            $injection = $this->injection($prop);
            if ($injection === true) {
                continue;
            }

            $meta = $this->extractPropertyMetadata($prop);
            if ($meta->omit) {
                continue;
            }

            $type = $prop->getType();
            [$schema, $example] = $this->buildQueryParameterSchema($prop, $meta);

            $props = [
                'name' => $this->queryName($inputNames[$meta->name] ?? $meta->name),
                'in' => 'query',
                'schema' => $schema,
            ];

            if ($injection === null
                && ! $type->allowsNull()
                && ! $meta->hasDefault
                && ! $this->propertyDeclaresLaravelDataOptional($prop)) {
                $props['required'] = true;
            }

            if ($meta->description) {
                $props['description'] = $meta->description;
            }

            if ($example !== null) {
                $props['example'] = $example;
            }

            $parameters[] = new OA\Parameter($props);
        }

        return $parameters;
    }

    /**
     * The schema of one query parameter, the property's type as a DTO schema maps it,
     * with the example the DTO declares (null when it declares none). For a single type
     * that example is the one its DTO schema shows; a union is a `oneOf` of each member
     * mapped the same way, with the example as declared, fitted to a string format.
     *
     * @return array{0: OA\Schema, 1: mixed}
     */
    private function buildQueryParameterSchema(ReflectionProperty $property, object $meta): array
    {
        $type = $property->getType();
        $members = [];

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if ($member instanceof ReflectionNamedType
                    && $member->getName() !== 'null'
                    && ! $this->isLaravelDataOptionalType($member)) {
                    $members[] = $member;
                }
            }
        }

        if (count($members) < 2) {
            $built = $this->buildProperty($meta);
            // Read before toParameterSchema(), which clears the items' example.
            $example = $meta->example === null ? null : $this->declaredExample($built, $meta);

            return [$this->inputDateFormat($this->toParameterSchema($built), $meta), $example];
        }

        // PHP reports union members in its own fixed order; list scalars before arrays
        // and classes, so the single-value form reads first.
        $rank = static fn (ReflectionNamedType $member): int => match (true) {
            $member->getName() === 'array' => 1,
            ! $member->isBuiltin() => 2,
            default => 0,
        };
        usort($members, static fn (ReflectionNamedType $a, ReflectionNamedType $b): int => $rank($a) <=> $rank($b));

        $props = [
            'oneOf' => array_map(
                function (ReflectionNamedType $member) use ($property): OA\Schema {
                    $memberMeta = $this->extractPropertyMetadata($property, $member);

                    return $this->inputDateFormat(
                        $this->toParameterSchema($this->buildProperty($memberMeta), withDefault: false),
                        $memberMeta,
                    );
                },
                $members,
            ),
        ];

        if ($meta->defaultValue !== null) {
            $props['default'] = $meta->defaultValue;
        }

        if ($type->allowsNull()) {
            $props['nullable'] = true;
        }

        $example = null;
        if ($meta->example !== null) {
            $example = $this->generateExample($meta);

            if ($this->allowsString($property)) {
                $example = $this->fitExample($example, $meta);
            }
        }

        return [new OA\Schema($props), $example];
    }

    /**
     * The example a property's DTO schema shows, for its query parameter: the
     * property's own, or a one-item list of its items' (where a scalar example on an
     * array sits); none where the schema shows none, as on a nested Data class. A
     * DateTime property's schema shows #[Example] as written, so its Faker reference is
     * resolved here, and fitted to a format its #[DateFormat] pins.
     */
    private function declaredExample(OA\Property $property, object $meta): mixed
    {
        if ($this->isDateTimeType($meta->typeName)) {
            $example = $this->generateExample($meta);

            return $meta->exactDateFormat ? $this->fitExample($example, $meta) : $example;
        }

        if ($property->example !== OpenApiGenerator::UNDEFINED) {
            return $property->example;
        }

        if ($property->items instanceof OA\Items && $property->items->example !== OpenApiGenerator::UNDEFINED) {
            return [$property->items->example];
        }

        return null;
    }

    /**
     * A query parameter is only ever input, so a DateTime one is documented in the
     * format its #[DateFormat] pins (`date` for `Y-m-d`), the form the request has to
     * send, instead of the `date-time` a DTO schema gives the value it serializes to.
     * #[Date] pins none: Laravel's `date` rule takes any date it can parse.
     */
    private function inputDateFormat(OA\Schema $schema, object $meta): OA\Schema
    {
        if ($meta->exactDateFormat && $meta->format !== null && $this->isDateTimeType($meta->typeName)) {
            $schema->format = $meta->format;
        }

        return $schema;
    }

    /**
     * A built property as a parameter schema: without the fields a parameter states
     * itself (name, description, example) and without generated item examples.
     *
     * @param  bool  $withDefault  False for a `oneOf` member, whose default belongs to the union.
     */
    private function toParameterSchema(OA\Property $property, bool $withDefault = true): OA\Schema
    {
        $props = [];

        foreach (get_object_vars($property) as $field => $value) {
            if ($value === OpenApiGenerator::UNDEFINED
                || str_starts_with($field, '_')
                || in_array($field, ['property', 'description', 'example'], true)
                || ($field === 'default' && ! $withDefault)) {
                continue;
            }

            $props[$field] = $value;
        }

        if (($props['items'] ?? null) instanceof OA\Items) {
            $props['items']->example = OpenApiGenerator::UNDEFINED;
        }

        return new OA\Schema($props);
    }

    /**
     * Each property's input name: the name a request carries it under, after the DTO's
     * MapInputName / MapName attributes and the app's input name mapping strategy.
     *
     * @return array<string, string> property name => input name
     */
    private function inputNames(string $className): array
    {
        $names = [];

        foreach (app(DataConfig::class)->getDataClass($className)->properties as $property) {
            $names[$property->name] = $property->inputMappedName ?? $property->name;
        }

        return $names;
    }

    /**
     * An input name as a query string carries it: laravel-data reads a dotted name as a
     * path into nested input, which a query string writes in brackets.
     */
    private function queryName(string $inputName): string
    {
        return preg_replace('/\.([^.]*)/', '[$1]', $inputName);
    }

    /**
     * Whether laravel-data fills the property from outside the request input
     * (#[FromRouteParameter], #[FromAuthenticatedUser], #[FromContainer] and their
     * *Property forms): true when that value replaces what the request sends, false
     * when it yields to it, null when the property is not filled that way.
     */
    private function injection(ReflectionProperty $property): ?bool
    {
        if (interface_exists(InjectsPropertyValue::class)) {
            return $this->firstAttribute($property, InjectsPropertyValue::class)?->shouldBeReplacedWhenPresentInPayload();
        }

        // Before laravel-data 4.12 only route parameters are injected, by two unrelated
        // attributes with the same flag.
        foreach ([FromRouteParameter::class, FromRouteParameterProperty::class] as $class) {
            if ($attribute = $this->firstAttribute($property, $class)) {
                return $attribute->replaceWhenPresentInBody;
            }
        }

        return null;
    }

    /**
     * The property's first attribute that is a $class (a subclass or implementation
     * counts), or null, also when the installed laravel-data has no such class.
     */
    private function firstAttribute(ReflectionProperty $property, string $class): ?object
    {
        if (! class_exists($class) && ! interface_exists($class)) {
            return null;
        }

        $attributes = $property->getAttributes($class, ReflectionAttribute::IS_INSTANCEOF);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * Public properties, inherited ones first: ancestors before descendants, each
     * class's own in declaration order.
     *
     * @return ReflectionProperty[]
     */
    private function inheritedFirst(ReflectionClass $reflection): array
    {
        $depth = static function (ReflectionClass $class): int {
            $depth = 0;
            while ($class = $class->getParentClass()) {
                $depth++;
            }

            return $depth;
        };

        $properties = $this->getClassProperties($reflection);
        usort(
            $properties,
            static fn (ReflectionProperty $a, ReflectionProperty $b): int => $depth($a->getDeclaringClass()) <=> $depth($b->getDeclaringClass()),
        );

        return $properties;
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
        if ($example !== null && !$this->isFakerReference($example)) {
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

    /**
     * Whether an #[Example] value is a `:faker` function reference rather than a literal.
     */
    private function isFakerReference(mixed $example): bool
    {
        return is_string($example) && str_starts_with($example, ExampleGenerator::FAKER_FUNCTION_PREFIX);
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
     * Discover DTO classes tagged as possible items for named oneOf groups.
     *
     * @param string[] $classes
     * @return array<string, array<int, object>>
     */
    private function discoverOneOfGroups(array $classes): array
    {
        $groups = [];

        foreach ($classes as $className) {
            $reflection = new ReflectionClass($className);

            foreach ($reflection->getAttributes(ItemType::class) as $attribute) {
                /** @var ItemType $itemType */
                $itemType = $attribute->newInstance();
                $schemaName = $this->resolveSchemaName($className);

                $groups[$itemType->group][] = (object) [
                    'className' => $className,
                    'schemaName' => $schemaName,
                    'itemSchemaName' => $this->inferItemSchemaName($schemaName),
                    'handle' => $itemType->handle ?? $this->inferItemHandle($schemaName),
                ];
            }
        }

        foreach ($groups as &$items) {
            usort(
                $items,
                static fn (object $a, object $b): int => $a->handle <=> $b->handle,
            );
        }

        return $groups;
    }

    /**
     * @return OA\Schema[]
     */
    private function buildOneOfItemSchemas(): array
    {
        $schemas = [];

        foreach ($this->oneOfGroups as $items) {
            foreach ($items as $item) {
                $schemas[] = new OA\Schema([
                    'schema' => $item->itemSchemaName,
                    'type' => 'object',
                    'required' => ['type', 'data'],
                    'properties' => [
                        new OA\Property([
                            'property' => 'type',
                            'type' => 'string',
                            'enum' => [$item->handle],
                            'example' => $item->handle,
                        ]),
                        new OA\Property([
                            'property' => 'data',
                            'description' => 'Item payload',
                            'allOf' => [
                                new OA\Schema(['ref' => '#/components/schemas/' . $item->schemaName]),
                            ],
                        ]),
                    ],
                ]);
            }
        }

        return $schemas;
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
    // Error DTOs (see ErrorContract)
    // -------------------------------------------------------------------------

    /**
     * Discovery rule: every non-abstract subclass of `errors.base_class` is an error.
     */
    private function isErrorClass(string $className): bool
    {
        return $this->errorContract->isErrorClass($className);
    }

    /**
     * Build the schemas for one error class: the `{Name}` details schema (when the
     * class has non-envelope properties), the `{Name}Body` error object and the
     * `{Name}Response` envelope around it. Records an ErrorDefinition for the
     * generator (components.responses) and the operation attacher.
     *
     * @return OA\Schema[]
     * @throws OpenApiDocsException on a broken error class contract or a duplicate code
     */
    private function buildErrorSchemas(string $className): array
    {
        $reflection = new ReflectionClass($className);
        ['code' => $code, 'message' => $message, 'status' => $status, 'markers' => $markers] = $this->errorContract->read($reflection);

        $this->assertCodeIsUnique($code, $className);

        $schemaName = class_basename($className);
        $detailsSchema = $this->buildSchema($className);

        $definition = new ErrorDefinition(
            className: $className,
            schemaName: $schemaName,
            responseSchemaName: $schemaName . 'Response',
            bodySchemaName: $schemaName . 'Body',
            code: $code,
            message: $message,
            status: $status,
            hasDetails: $detailsSchema !== null,
            markers: $markers,
        );
        $this->errorDefinitions[$definition->identifier()] = $definition;

        $schemas = [];
        if ($detailsSchema !== null) {
            $schemas[] = $detailsSchema;
        }
        $schemas[] = $this->buildErrorBodySchema($reflection, $definition);
        $schemas[] = $this->errorEnvelope->responseSchema($definition);

        return $schemas;
    }

    /**
     * Build the schemas for one error declared as an enum case: the `{Name}Body` error
     * object and the `{Name}Response` envelope around it. A case has no properties, so
     * there is no details schema, and the names match those of the class of the same
     * name, so moving an error between the two forms leaves the spec unchanged.
     *
     * @return OA\Schema[]
     *
     * @throws OpenApiDocsException on a broken case contract or a duplicate code
     */
    private function buildErrorCaseSchemas(string $enum, ReflectionEnumBackedCase $case): array
    {
        ['code' => $code, 'message' => $message, 'status' => $status] = $this->errorContract->readCase($case);

        $this->assertCodeIsUnique($code, $enum . '::' . $case->getName());

        $schemaName = Str::studly($case->getName()) . (string) $this->errorConfig['case_suffix'];

        $definition = new ErrorDefinition(
            className: $enum,
            schemaName: $schemaName,
            responseSchemaName: $schemaName . 'Response',
            bodySchemaName: $schemaName . 'Body',
            code: $code,
            message: $message,
            status: $status,
            hasDetails: false,
            markers: [],
            case: $case->getName(),
        );
        $this->errorDefinitions[$definition->identifier()] = $definition;

        return [
            $this->errorEnvelope->bodySchema($definition),
            $this->errorEnvelope->responseSchema($definition),
        ];
    }

    /**
     * @throws OpenApiDocsException when another error already declares this code,
     *                              naming both sources whether they are classes or cases.
     */
    private function assertCodeIsUnique(string $code, string $source): void
    {
        foreach ($this->errorDefinitions as $existing) {
            if ($existing->code === $code) {
                throw new OpenApiDocsException(sprintf(
                    'Duplicate error code \'%s\': declared by both %s and %s.',
                    $code,
                    $existing->identifier(),
                    $source,
                ));
            }
        }
    }

    /**
     * Build the `{Name}Body` error object: the envelope's standard fields, the
     * class's #[EnvelopeField] properties, and one `params` property per MESSAGE
     * marker, built from the same-named public property like any DTO property. The
     * message example fills each marker that has an #[Example] value
     * (see ErrorEnvelope::bodySchema()).
     */
    private function buildErrorBodySchema(ReflectionClass $reflection, ErrorDefinition $definition): OA\Schema
    {
        $properties = [];
        $required = [];

        foreach ($this->getClassProperties($reflection) as $prop) {
            $meta = $this->extractPropertyMetadata($prop);
            if ($meta->omit || ! $meta->envelopeField) {
                continue;
            }

            $properties[] = $this->buildProperty($meta);
            if ($meta->required) {
                $required[] = $meta->name;
            }
        }

        $paramProperties = [];
        $messageExample = $definition->message;

        foreach ($definition->markers as $marker) {
            $meta = $this->extractPropertyMetadata($reflection->getProperty($marker));
            $param = $this->buildProperty($meta);
            $paramProperties[] = $param;

            if ($meta->example !== null && $param->example !== OpenApiGenerator::UNDEFINED) {
                $messageExample = str_replace('{' . $marker . '}', $this->exampleToText($param->example), $messageExample);
            }
        }

        return $this->errorEnvelope->bodySchema($definition, $properties, $required, $paramProperties, $messageExample);
    }

    /**
     * An example value as it would read inside a filled-in message.
     */
    private function exampleToText(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }

    /**
     * Build the error-code enum schema (`errors.code_schema`, default "ErrorCode")
     * for the given errors: their codes, with a description listing each code, its
     * HTTP status and its MESSAGE, which makes it the error-codes reference page.
     * OpenApiGenerator passes only the errors the documentation set's operations
     * reference, so each set's code list is honest.
     *
     * @param  array<int, ErrorDefinition>  $definitions
     */
    public function buildErrorCodeSchema(array $definitions): ?OA\Schema
    {
        if ($definitions === []) {
            return null;
        }

        $definitions = array_values($definitions);
        usort($definitions, static fn (ErrorDefinition $a, ErrorDefinition $b): int => $a->code <=> $b->code);

        $lines = array_map(
            static fn (ErrorDefinition $d): string => sprintf('- `%s` (HTTP %d): %s', $d->code, $d->status, $d->message),
            $definitions,
        );

        return new OA\Schema([
            'schema' => $this->getErrorCodeSchemaName(),
            'type' => 'string',
            'description' => 'Machine-readable error codes returned in `'
                . $this->errorEnvelope->codePath()
                . "` of error responses.\n\n" . implode("\n", $lines),
            'enum' => array_map(static fn (ErrorDefinition $d): string => $d->code, $definitions),
            'example' => $definitions[0]->code,
        ]);
    }

    // -------------------------------------------------------------------------
    // Docblock Parsing (Laravel Data v4 support)
    // -------------------------------------------------------------------------

    /**
     * Detect a string-keyed scalar map in a `@var` docblock — `array<string, T>` with
     * T a scalar, `mixed`, or a list of scalars (`string[]` / `array<string>`) —
     * and return the OpenAPI schema properties for the map's values, or null.
     * Such properties are emitted as `object` + `additionalProperties` rather
     * than a list (e.g. a validation `errors` map: `array<string, string[]>`).
     *
     * @return array<string, mixed>|null
     */
    private function resolveStringMapValueFromDocBlock(?string $docComment): ?array
    {
        if ($docComment === null) {
            return null;
        }

        if (! preg_match('/@var\s+array<\s*string\s*,\s*([^>]+(?:<[^>]*>)?)\s*>/', $docComment, $m)) {
            return null;
        }

        $value = trim($m[1]);
        $scalar = static fn (string $t): ?string => match ($t) {
            'string' => 'string',
            'int', 'integer' => 'integer',
            'float', 'double' => 'number',
            'bool', 'boolean' => 'boolean',
            default => null,
        };

        if ($value === 'mixed') {
            return [];
        }

        if ($type = $scalar($value)) {
            return ['type' => $type];
        }

        // string[] or array<string>
        if (preg_match('/^(\w+)\[\]$/', $value, $lm) || preg_match('/^array<\s*(\w+)\s*>$/', $value, $lm)) {
            if ($itemType = $scalar($lm[1])) {
                return ['type' => 'array', 'items' => new OA\Items(['type' => $itemType])];
            }
        }

        return null;
    }

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

    private function inferItemSchemaName(string $schemaName): string
    {
        foreach (['Resource', 'Data'] as $suffix) {
            if (str_ends_with($schemaName, $suffix)) {
                $schemaName = substr($schemaName, 0, -strlen($suffix));
                break;
            }
        }

        return $schemaName . 'Item';
    }

    private function inferItemHandle(string $schemaName): string
    {
        foreach (['Resource', 'Data'] as $suffix) {
            if (str_ends_with($schemaName, $suffix)) {
                $schemaName = substr($schemaName, 0, -strlen($suffix));
                break;
            }
        }

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $schemaName));
    }
}
