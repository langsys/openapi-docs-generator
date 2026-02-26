<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Omit extends OpenApiAttribute
{
}
