<?php

namespace Langsys\SwaggerAutoGenerator\Generators\Swagger;

use Langsys\SwaggerAutoGenerator\Generators\Swagger\Traits\PrettyPrints;

class Property implements PrintsSwagger
{
    use PrettyPrints;

    public function __construct(
        public string $name,
        public string $description,
        // This could be either an example value or a whole child schema for object or array types
        public string|int|bool|Schema $content,
        public string $type,
        public bool $required = false,
        protected $prettify = true, //
        public array $enum = [],
        public ?string $default = null
    ) {}

    public function toSwagger(bool $cascade = false, int $level = 0): string
    {
        $contentIsSchema = $this->content instanceof Schema;
        $nonPrimitiveProperty = $contentIsSchema || $this->type === 'array';
        $newLines = (int)$nonPrimitiveProperty;
        $attributeLevel = $nonPrimitiveProperty ? $level + 1 : $level;

        $swaggerProperty = $this->prettyPrint('@OA\Property(', $level, $newLines, addPrefix: true);
        $swaggerProperty .= $this->addBasicAttributes($attributeLevel, $newLines, $nonPrimitiveProperty);

        if ($this->type === 'array') {
            $swaggerProperty .= $this->handleArrayType($attributeLevel, $newLines, $nonPrimitiveProperty, $contentIsSchema, $cascade);
        } elseif ($contentIsSchema) {
            $swaggerProperty .= $this->content->toSwagger(true, $cascade, $attributeLevel);
        } elseif ($this->type === 'string') {
            $swaggerProperty .= $this->prettyPrint(
                "example='{$this->content}',",
                $attributeLevel,
                $newLines,
                $nonPrimitiveProperty
            );
        } elseif (!empty($this->enum) && $this->type === 'enum') {
            $swaggerProperty .= $this->handleEnumType($attributeLevel, $newLines, $nonPrimitiveProperty);
        } else {
            $swaggerProperty .= $this->handleOtherTypes($attributeLevel, $newLines, $nonPrimitiveProperty);
        }

        $swaggerProperty .= $this->prettyPrint('),', $level, addPrefix: $nonPrimitiveProperty);

        return $swaggerProperty;
    }

    private function addBasicAttributes(int $level, int $newLines, bool $nonPrimitiveProperty): string
    {
        $result = $this->prettyPrint(
            "property='{$this->name}',",
            $level,
            $newLines,
            $nonPrimitiveProperty
        );
        $result .= $this->prettyPrint(
            "type='{$this->type}',",
            $level,
            $newLines,
            $nonPrimitiveProperty
        );
        if ($this->description) {
            $result .= $this->prettyPrint(
                "description='{$this->description}',",
                $level,
                $newLines,
                $nonPrimitiveProperty
            );
        }
        if ($this->default !== null && in_array($this->type, ['string', 'int', 'bool'])) {
            $defaultValue = $this->formatDefaultValue();
            $result .= $this->prettyPrint(
                "default=$defaultValue,",
                $level,
                $newLines,
                $nonPrimitiveProperty
            );
        }
        return $result;
    }

    private function formatDefaultValue(): string
    {
        if ($this->type === 'string') {
            return "\"$this->default\"";
        } elseif ($this->type === 'bool') {
            return $this->default ? 'true' : 'false';
        } else {
            return $this->default;
        }
    }

    private function handleArrayType(int $level, int $newLines, bool $nonPrimitiveProperty, bool $contentIsSchema, bool $cascade): string
    {
        $type = gettype($this->content);
        $contentLevel = $contentIsSchema ? $level + 1 : $level;
        $content = $contentIsSchema ?
            $this->content->toSwagger(true, $cascade, $contentLevel) :
            "type='$type', example='{$this->content}'";

        $result = $this->prettyPrint(
            "@OA\Items(",
            $level,
            (int)$contentIsSchema,
            $nonPrimitiveProperty
        );
        $result .= $content;
        $result .= $this->prettyPrint(
            "),",
            $level,
            $newLines,
            $contentIsSchema
        );
        return $result;
    }

    private function handleEnumType(int $level, int $newLines, bool $nonPrimitiveProperty): string
    {
        $randomKey = array_rand($this->enum);
        $exampleValue = $this->enum[$randomKey];
        $enumValues = implode(', ', array_map(function ($value) {
            return is_string($value) ? "'$value'" : $value;
        }, $this->enum));
        $enumValues = "{" . $enumValues . "}";
        return $this->prettyPrint(
            "enum={$enumValues}, example= '$exampleValue',",
            $level,
            $newLines,
            $nonPrimitiveProperty
        );
    }

    private function handleOtherTypes(int $level, int $newLines, bool $nonPrimitiveProperty): string
    {
        $content = $this->content;

        if (is_bool($content)) {
            $content = $content ? 'true' : 'false';
        }

        if ($this->type === 'int') {
            $content = (int)$content;
        }

        return $this->prettyPrint(
            "example=$content,",
            $level,
            $newLines,
            $nonPrimitiveProperty
        );
    }
}
