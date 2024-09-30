<?php

namespace Langsys\SwaggerAutoGenerator\Generators\Swagger;

use Langsys\SwaggerAutoGenerator\Generators\Swagger\Attributes\SwaggerAttribute;
use Langsys\SwaggerAutoGenerator\Generators\Swagger\Traits\PrettyPrints;
use Exception;
use Illuminate\Support\Collection;
use ReflectionClass;
use ReflectionProperty;
use ReflectionType;
use Spatie\LaravelData\Attributes\DataCollectionOf;

class Schema implements PrintsSwagger
{
    use PrettyPrints;

    private ExampleGenerator $exampleGenerator;
    public bool|null $isRequest = null;
    public array|Collection $properties;
    protected bool $isResource = false;

    public function __construct(
        public string $name,
        public bool $prettify = true,
        protected bool $fromClass = true,
        protected bool $virutalSchema = false
    ) {
        $this->name = class_basename($name);
        // Identifies resource and also removes it from schema name to avoid too long names
        if (str_contains($name, 'Resource')) {
            $this->isResource = true;
            $this->name = str_replace('Resource', '', $this->name);
        }
        if (is_null($this->isRequest)) {
            $this->isRequest = str_contains($this->name, 'Request');
        }
        $this->exampleGenerator = new ExampleGenerator();
        $this->properties = collect();

        if ($this->fromClass) {
            $this->_generateProperties($name);
        }
    }

    public function addProperty(Property $property): void
    {
        $this->properties->push($property);
    }

    public function isEmpty()
    {
        return $this->properties->isEmpty();
    }

    public function isResource()
    {
        return $this->isResource;
    }

    public function toSwagger(bool $onlyProperties = false, bool $cascade = false, int $level = 0): string
    {
        if ($this->_shouldPrintSchemaReference($onlyProperties, $cascade)) {
            return $this->_printSchemaReference($level);
        }

        $swaggerSchema = $this->_initializeSwaggerSchema($onlyProperties, $level);
        $required = $this->_getRequiredProperties();
        $propertiesSchema = $this->_generatePropertiesSchema($cascade, $level);

        $swaggerSchema .= $this->_addRequiredPropertiesIfNeeded($required, $level);
        $swaggerSchema .= $propertiesSchema . $this->prettyPrint(' ),', $level, 2, true);

        return $this->_formatSwaggerSchema($swaggerSchema);
    }

    private function _shouldPrintSchemaReference(bool $onlyProperties, bool $cascade): bool
    {
        return $onlyProperties && !$cascade && !$this->virutalSchema;
    }

    private function _printSchemaReference(int $level): string
    {
        $schemaByReference = $this->prettyPrint("allOf={", $level, addPrefix: true);
        $schemaByReference .= $this->prettyPrint(
            "@OA\Schema(ref = '#/components/schemas/{$this->name}'),",
            $level + 1,
            addPrefix: true
        );
        $schemaByReference .= $this->prettyPrint("}", $level, addPrefix: true);

        return $schemaByReference;
    }

    private function _initializeSwaggerSchema(bool $onlyProperties, int $level): string
    {
        return $onlyProperties ? '' :
            $this->prettyPrint(" @OA\Schema( schema='{$this->name}',", $level, addPrefix: true);
    }

    private function _getRequiredProperties(): array
    {
        return $this->properties->filter->required->pluck('name')->toArray();
    }

    private function _generatePropertiesSchema(bool $cascade, int $level): string
    {
        return $this->properties->map(function (Property $property) use ($cascade, $level) {
            return $property->toSwagger($cascade, $level + 1);
        })->implode('');
    }

    private function _addRequiredPropertiesIfNeeded(array $required, int $level): string
    {
        if ($this->isRequest && !empty($required)) {
            return $this->prettyPrint(
                'required={"' . implode('","', $required) . '"},',
                $level + 1,
                addPrefix: true
            );
        }
        return '';
    }

    private function _formatSwaggerSchema(string $swaggerSchema): string
    {
        return str_replace(["'", ExampleGenerator::SINGLE_QUOTE_IDENTIFIER], ['"', "'"], $swaggerSchema);
    }

    /**
     * @throws \ReflectionException
     * @throws \Throwable
     */
    private function _generateProperties(string $className)
    {
        $reflection = new ReflectionClass($className);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            $propertyMeta = $this->_getPropertyMetadata($property);

            if ($propertyMeta->shouldOmit) {
                continue;
            }

            $this->_processProperty($propertyMeta);
        }
    }

    private function _getPropertyMetadata(ReflectionProperty $property): object
    {
        $type = $this->_getPropertyType($property);
        $attributeMeta = $this->_toAttributeMeta($property->getAttributes());

        $typeName = $type->getName();
        $defaultValue = $this->_getPropertyDefaultValue($property);

        return (object) [
            'name' => $property->getName(),
            'type' => $type,
            'typeName' => $typeName === 'Illuminate\Support\Collection' ? 'array' : $typeName, // We represent collections as arrays
            'typeClassName' => array_reverse(explode('\\', $type->getName()))[0],
            'attributeMeta' => $attributeMeta,
            'example' => $attributeMeta->example->value ?? null,
            'arguments' => $attributeMeta->example->arguments ?? [],
            'shouldOmit' => property_exists($attributeMeta, 'omit'),
            'defaultValue' => $defaultValue,
        ];
    }

    private function _getPropertyDefaultValue(ReflectionProperty $property): mixed
    {
        $class = $property->getDeclaringClass();
        $constructor = $class->getConstructor();

        if (!$constructor) {
            return null;
        }

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->getName() === $property->getName() && $parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
        }

        return null;
    }

    private function _processProperty(object $propertyMeta): void
    {
        $content = $this->_generatePropertyContent($propertyMeta);
        $typeName = $this->_determinePropertyType($propertyMeta);
        $subSchema = $this->_getSubSchema($propertyMeta);
        $enumValues = $this->_getEnumValues($propertyMeta);

        $newSchema = $this->_handleSpecialCases($propertyMeta, $typeName, $content, $subSchema);

        $this->_addPropertyToSchema($propertyMeta, $typeName, $content, $subSchema, $enumValues, $newSchema);
    }

    private function _generatePropertyContent(object $propertyMeta)
    {
        $example = $propertyMeta->example;
        $arguments = $propertyMeta->arguments;
        $exampleFunction = $example ?? $propertyMeta->name;
        $content = $example;

        if (str_starts_with($example, ExampleGenerator::FAKER_FUNCTION_PREFIX) || !$example) {
            $exampleFunction = (string)$exampleFunction;
            $arguments = [...$arguments, 'type' => $propertyMeta->typeName];
            // As directly declared functions inside example generator take regular parameters then spread the array
            // Otherwise pass on the arguments array to the magic function
            $content = method_exists($this->exampleGenerator, $exampleFunction) ?
                $this->exampleGenerator->$exampleFunction(...array_values($arguments)) :
                $this->exampleGenerator->$exampleFunction($arguments);
        }

        // Lets set all booleans to true if they're not declared
        if ($propertyMeta->typeName === 'bool' && $content === '') {
            $content = is_bool($example) ? $example : true;
        }

        return $content;
    }

    private function _determinePropertyType(object $propertyMeta): string
    {
        $typeName = $propertyMeta->typeName;

        // We represent collections as arrays
        if ($propertyMeta->typeClassName === 'Collection') {
            $typeName = 'array';
        }

        if (enum_exists($typeName)) {
            $typeName = 'enum';
        }

        return $typeName;
    }

    private function _getSubSchema(object $propertyMeta): ?string
    {
        $typeName = $propertyMeta->typeName;
        $type = $propertyMeta->type;

        if ($typeName !== 'array' && !$type->isBuiltin() && !enum_exists($typeName)) {
            return $typeName;
        }

        return null;
    }

    private function _getEnumValues(object $propertyMeta): array
    {
        $typeName = $propertyMeta->typeName;

        if (enum_exists($typeName)) {
            return array_column($typeName::cases(), 'value');
        }

        return [];
    }

    private function _handleSpecialCases(object $propertyMeta, string &$typeName, &$content, ?string &$subSchema): ?Schema
    {
        $attributeMeta = $propertyMeta->attributeMeta;

        if ($typeName === 'array' && property_exists($attributeMeta, 'groupedcollection')) {
            $newSchema = new Schema("{$propertyMeta->name}GroupedCollection", $this->prettify, false, true);
            $newSchema->addProperty(
                new Property(
                    name: $attributeMeta->groupedcollection->value,
                    description: '',
                    content: $content,
                    type: $typeName,
                    required: !$propertyMeta->type?->allowsNull(),
                    prettify: $this->prettify,
                    enum: []
                )
            );

            $content = $newSchema;
            return $newSchema;
        }

        if ($typeName === 'object' && property_exists($attributeMeta, 'groupedcollection')) {
            $newSchema = new Schema("{$propertyMeta->name}GroupedCollection", $this->prettify, false, true);
            $newSchema->addProperty(
                new Property(
                    name: $attributeMeta->groupedcollection->value,
                    description: '',
                    content: $subSchema ? new Schema($subSchema, $this->prettify) : $content,
                    type: $typeName,
                    required: !$propertyMeta->type?->allowsNull(),
                    prettify: $this->prettify,
                    enum: []
                )
            );

            $content = $newSchema;
            return $newSchema;
        }

        if (str_contains($typeName, 'DataCollection') && property_exists($attributeMeta, 'collectionOf')) {
            $typeName = 'array';
            $subSchema = $attributeMeta->collectionOf->value;
            throw_if(
                !$subSchema,
                Exception::class,
                'DataCollectionOf attribute definition is necessary when defining a property as DataCollection.'
            );
        }

        return null;
    }

    private function _addPropertyToSchema(object $propertyMeta, string $typeName, $content, ?string $subSchema, array $enumValues, ?Schema $newSchema): void
    {
        $this->addProperty(
            new Property(
                name: $propertyMeta->name,
                description: $propertyMeta->attributeMeta->description->value ?? '',
                content: $newSchema ?? ($subSchema ? new Schema($subSchema, $this->prettify) : $content),
                type: $typeName,
                required: !$propertyMeta->type?->allowsNull(),
                prettify: $this->prettify,
                enum: $enumValues,
                default: $propertyMeta->defaultValue
            )
        );
    }

    private function _toAttributeMeta(array $attributes): object
    {
        $attributeValues = (object)[];

        foreach ($attributes as $attribute) {
            $attribute = $attribute->newInstance();
            if ($attribute instanceof DataCollectionOf) {
                $attributeName = 'collectionOf';
                $content = $attribute->class;
            } elseif ($attribute instanceof SwaggerAttribute) {
                $attributeName = $attribute->getName();
                $content = property_exists($attribute, 'content') ? $attribute->content : null;
            } else {
                continue;
            }

            $attributeValues->$attributeName = (object)[];
            $attributeValues->$attributeName->value = $content;
            $attributeValues->$attributeName->arguments = $attribute->arguments ?? [];
        }
        return $attributeValues;
    }

    private function  _getPropertyType(ReflectionProperty $property): ReflectionType
    {
        $type = $property->getType();
        if (method_exists($type, 'getTypes')) {
            [$type] = $type?->getTypes();
        }

        return $type;
    }
}
