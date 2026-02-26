<?php

namespace Langsys\OpenApiDocsGenerator\Generators\Attributes;

use Attribute;

class OpenApiAttribute
{
    public function getName()
    {
        return strtolower(class_basename(get_class($this)));
    }
}
