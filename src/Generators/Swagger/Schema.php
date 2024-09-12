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
        if ($this->shouldPrintSchemaReference($onlyProperties, $cascade)) {
            return $this->printSchemaReference($level);
        }

        $swaggerSchema = $this->initializeSwaggerSchema($onlyProperties, $level);
        $required = $this->getRequiredProperties();
        $propertiesSchema = $this->generatePropertiesSchema($cascade, $level);

        $swaggerSchema .= $this->addRequiredPropertiesIfNeeded($required, $level);
        $swaggerSchema .= $propertiesSchema . $this->prettyPrint(' ),', $level, 2, true);

        return $this->formatSwaggerSchema($swaggerSchema);
    }

    private function shouldPrintSchemaReference(bool $onlyProperties, bool $cascade): bool
    {
        return $onlyProperties && !$cascade && !$this->virutalSchema;
    }

    private function printSchemaReference(int $level): string
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

    private function initializeSwaggerSchema(bool $onlyProperties, int $level): string
    {
        return $onlyProperties ? '' :
            $this->prettyPrint(" @OA\Schema( schema='{$this->name}',", $level, addPrefix: true);
    }

    private function getRequiredProperties(): array
    {
        return $this->properties->filter->required->pluck('name')->toArray();
    }

    private function generatePropertiesSchema(bool $cascade, int $level): string
    {
        return $this->properties->map(function (Property $property) use ($cascade, $level) {
            return $property->toSwagger($cascade, $level + 1);
        })->implode('');
    }

    private function addRequiredPropertiesIfNeeded(array $required, int $level): string
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

    private function formatSwaggerSchema(string $swaggerSchema): string
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
            $propertyMeta = $this->getPropertyMetadata($property);
            
            if ($propertyMeta->shouldOmit) {
                continue;
            }

            $this->processProperty($propertyMeta);
        }
    }

    private function getPropertyMetadata(ReflectionProperty $property): object
    {
        $type = $this->_getPropertyType($property);
        $attributeMeta = $this->_toAttributeMeta($property->getAttributes());
        
        return (object) [
            'name' => $property->getName(),
            'type' => $type,
            'typeName' => $type->getName(),
            'typeClassName' => array_reverse(explode('\\', $type->getName()))[0],
            'attributeMeta' => $attributeMeta,
            'example' => $attributeMeta->example->value ?? null,
            'arguments' => $attributeMeta->example->arguments ?? [],
            'shouldOmit' => property_exists($attributeMeta, 'omit'),
        ];
    }

    private function processProperty(object $propertyMeta): void
    {
        $content = $this->generatePropertyContent($propertyMeta);
        $typeName = $this->determinePropertyType($propertyMeta);
        $subSchema = $this->getSubSchema($propertyMeta);
        $enumValues = $this->getEnumValues($propertyMeta);
        
        $newSchema = $this->handleSpecialCases($propertyMeta, $typeName, $content, $subSchema);
        
        $this->addPropertyToSchema($propertyMeta, $typeName, $content, $subSchema, $enumValues, $newSchema);
    }

    private function generatePropertyContent(object $propertyMeta)
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

    private function determinePropertyType(object $propertyMeta): string
    {
        $typeName = $propertyMeta->typeName;

        // We represent collections as arrays
        if($propertyMeta->typeClassName === 'Collection') {
            $typeName = 'array';
        }

        return $typeName;
    }

    private function getSubSchema(object $propertyMeta): ?string
    {
        $typeName = $propertyMeta->typeName;
        $type = $propertyMeta->type;

        if ($typeName !== 'array' && !$type->isBuiltin() && !enum_exists($typeName)) {
            return $typeName;
        }

        return null;
    }

    private function getEnumValues(object $propertyMeta): array
    {
        $typeName = $propertyMeta->typeName;

        if (enum_exists($typeName)) {
            return array_column($typeName::cases(), 'value');
        }

        return [];
    }

    private function handleSpecialCases(object $propertyMeta, string &$typeName, &$content, ?string $subSchema): ?Schema
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
                ));

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
                ));

            $content = $newSchema;
            return $newSchema;
        }

        if ($typeName === 'object' && property_exists($attributeMeta, 'collectionOf')) {
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

    private function addPropertyToSchema(object $propertyMeta, string $typeName, $content, ?string $subSchema, array $enumValues, ?Schema $newSchema): void
    {
        $this->addProperty(
            new Property(
                name: $propertyMeta->name,
                description: $propertyMeta->attributeMeta->description->value ?? '',
                content: $newSchema ?? ($subSchema ? new Schema($subSchema, $this->prettify) : $content),
                type: $typeName,
                required: !$propertyMeta->type?->allowsNull(),
                prettify: $this->prettify,
                enum: $enumValues
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
                $content = property_exists($attribute,'content') ? $attribute->content : null;
            } else {
                continue;
            }

            $attributeValues->$attributeName = (object)[];
            $attributeValues->$attributeName->value = $content;
            $attributeValues->$attributeName->arguments = $attribute->arguments ?? [];
        }
        return $attributeValues;
    }

    private function _getPropertyType(ReflectionProperty $property): ReflectionType
    {
        $type = $property->getType();
        if (method_exists($type, 'getTypes')) {
            [$type] = $type?->getTypes();
        }

        return $type;
    }
}
