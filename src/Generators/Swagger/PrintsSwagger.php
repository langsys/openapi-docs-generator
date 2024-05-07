<?php

namespace Langsys\SwaggerAutoGenerator\Generators\Swagger;

interface PrintsSwagger
{
    public function toSwagger(): string;
}
