<?php

namespace Langsys\SwaggerAutoGenerator\Generators\Swagger\Attributes;

use Attribute;

class SwaggerAttribute
{
    public function getName()
    {
        return strtolower(class_basename(get_class($this)));
    }
}
