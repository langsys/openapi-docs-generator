<?php

namespace Langsys\SwaggerAutoGenerator\Generators\Swagger\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Omit extends SwaggerAttribute
{
}
