<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Example extends OpenApiAttribute
{
    /**
     * @param  string|int|bool|float|array<array-key, mixed>  $content  The example, or a
     *     `:faker` function reference. An array is the example of an array property: a
     *     list's, whose items take its first element, or an object's.
     */
    public function __construct(
        public string|int|bool|float|array $content,
        public array $arguments = [],
    ) {
    }
}
