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
        protected $prettify = true
    ) {
    }

    public function toSwagger(bool $cascade = false, int $level = 0): string
    {
        $contentIsSchema = $this->content instanceof Schema;

        $nonPrimitiveProperty = $contentIsSchema || $this->type === 'array';
        $newLines = (int)$nonPrimitiveProperty;

        $swaggerProperty = $this->prettyPrint('@OA\Property(', $level, $newLines, addPrefix: true);

        $attributeLevel = $nonPrimitiveProperty ? $level + 1 : $level;

        $swaggerProperty .= $this->prettyPrint(
            "property='{$this->name}',",
            $attributeLevel,
            $newLines,
            $nonPrimitiveProperty
        );

        $swaggerProperty .= $this->prettyPrint(
            "type='{$this->type}',",
            $attributeLevel,
            $newLines,
            $nonPrimitiveProperty
        );


        if ($this->description) {
            $swaggerProperty .= $this->prettyPrint(
                "description='{$this->description}',",
                $attributeLevel,
                $newLines,
                $nonPrimitiveProperty
            );
        }

        if ($this->type === 'array') {
            $type = gettype($this->content);
            $contentLevel = $contentIsSchema ? $attributeLevel + 1 : $attributeLevel;
            $content = $contentIsSchema ?
                $this->content->toSwagger(true, $cascade, $contentLevel) :
                "type='$type', example='{$this->content}'";

            $swaggerProperty .= $this->prettyPrint(
                "@OA\Items(",
                $attributeLevel,
                (int)$contentIsSchema,
                $nonPrimitiveProperty
            );

            $swaggerProperty .= $content;

            $swaggerProperty .= $this->prettyPrint(
                "),",
                $attributeLevel,
                $newLines,
                $contentIsSchema
            );
        } elseif ($contentIsSchema) {
            // If cascade is on then we will output the whole object instead of just a reference
            $swaggerProperty .= $this->content->toSwagger(true, $cascade, $attributeLevel);
        } elseif ($this->type === 'string') {
            $swaggerProperty .= $this->prettyPrint(
                "example='{$this->content}',",
                $attributeLevel,
                $newLines,
                $nonPrimitiveProperty
            );
        } else {
            $content = $this->content;

            if (is_bool($content)) {
                $content = $content ? 'true' : 'false';
            }

            if ($this->type === 'int') {
                $content = (int)$content;
            }

            $swaggerProperty .= $this->prettyPrint(
                "example=$content,",
                $attributeLevel,
                $newLines,
                $nonPrimitiveProperty
            );
        }

        $swaggerProperty .= $this->prettyPrint('),', $level, addPrefix: $nonPrimitiveProperty);

        return $swaggerProperty;
    }
}
