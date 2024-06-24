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
        // This means that we should only print a reference of the Schema instead of the whole thing
        if ($onlyProperties && !$cascade && !$this->virutalSchema) {
            $schemaByReference = $this->prettyPrint("allOf={", $level, addPrefix: true);
            $schemaByReference .= $this->prettyPrint(
                "@OA\Schema(ref = '#/components/schemas/{$this->name}'),",
                $level + 1,
                addPrefix: true
            );
            $schemaByReference .= $this->prettyPrint("}", $level, addPrefix: true);

            return $schemaByReference;
        }
        $swaggerSchema = $onlyProperties ? '' :
            $this->prettyPrint(" @OA\Schema( schema='{$this->name}',", $level, addPrefix: true);
        $required = [];
        $this->properties->each(function (Property $property) use (&$propertiesSchema, &$required, $cascade, $level) {
            $propertiesSchema .= $property->toSwagger($cascade, $level + 1);
            if ($property->required) {
                $required[] = $property->name;
            }
        });

        if (!$onlyProperties && $this->isRequest && !empty($required)) {
            $swaggerSchema .= $this->prettyPrint(
                'required={"'.implode('","', $required).'"},',
                $level + 1,
                addPrefix: true
            );
        }

        $swaggerSchema .= $propertiesSchema.$this->prettyPrint(' ),', $level, 2, true);
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
            $propertyName = $property->getName();
            $type = $this->_getPropertyType($property);
            $typeName = $type->getName();
            $attributeMeta = $this->_toAttributeMeta($property->getAttributes());
            $example = $attributeMeta->example->value ?? null;
            $arguments = $attributeMeta->example->arguments ?? [];
            $exampleFunction = $example ?? $propertyName;
            $content = $example;
            $subSchema = null;
            $newSchema = null;

            if(property_exists($attributeMeta,'omit')) {
                continue;
            }

            $enumValues = [];
            if (str_starts_with($example, ExampleGenerator::FAKER_FUNCTION_PREFIX) || !$example) {
                $exampleFunction = (string)$exampleFunction;
                $arguments = [...$arguments, 'type' => $typeName];
                // As directly declared functions inside example generator take regular parameters then spread the array
                // Otherwise pass on the arguments array to the magic function
                $content = method_exists($this->exampleGenerator, $exampleFunction) ?
                    $this->exampleGenerator->$exampleFunction(...array_values($arguments)) :
                    $this->exampleGenerator->$exampleFunction($arguments);
            }

            // Lets set all booleans to true if they're not declared
            if ($typeName === 'bool' && $content === '') {
                $content = is_bool($example) ? $example : true;
            }

            if (!$type->isBuiltin() && !enum_exists($typeName)) {
                $subSchema = $typeName;
                $typeName = 'object';
            }

            if (enum_exists($typeName)) {
                $enumValues = array_column($typeName::cases(), 'value');
                $typeName = 'enum';

            }

            // Data collections should be represented as arrays of the type defined in
            if (str_contains($type?->getName(), 'DataCollection')) {
                $typeName = 'array';
                $subSchema = $attributeMeta?->collectionOf?->value;
                throw_if(
                    !$subSchema,
                    Exception::class,
                    'DataCollectionOf attribute definition is necessary when defining a property as DataCollection.'
                );

                if(property_exists($attributeMeta,'groupedcollection')) {
                    $typeName = 'object';
                    // Virtual schema forces to cascade a not use an allOf reference. allOf would not work here as this is not a schema created from a data object, but only to show a grouping
                    $newSchema = new Schema("{$propertyName}GroupedCollection", $this->prettify, false,true);
                    $newSchema->addProperty(
                        new Property(
                            name: $attributeMeta->groupedcollection->value,
                            description: '',
                            content: $subSchema ? new Schema($subSchema, $this->prettify) : $content,
                            type: $typeName,
                            required: !$type?->allowsNull(),
                            prettify: $this->prettify,
                            enum: $enumValues
                        ));
                    $content = $newSchema;
                }
            }


            if(!$newSchema) {
                $content = $subSchema ? new Schema($subSchema, $this->prettify) : $content;
            }

            $this->addProperty(
                new Property(
                    name: $propertyName,
                    description: $attributeMeta->description->value ?? '',
                    content: $content,
                    type: $typeName,
                    required: !$type?->allowsNull(),
                    prettify: $this->prettify,
                    enum: $enumValues
                )
            );
        }
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
