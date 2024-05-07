<?php

namespace Langsys\SwaggerAutoGenerator\Generators\Swagger\Traits;

trait PrettyPrints
{
    public function prettyPrint(string $content, int $level, int $EOL = 1, bool $addPrefix = false): string
    {
        $tab = chr(9);

        if ($this->prettify) {
            $prefix = $addPrefix ? '*' . str_repeat($tab, $level) : '';
            $suffix = $EOL > 0 ? PHP_EOL : '';

            if ($EOL > 1) {
                $suffix .= str_repeat('*' . PHP_EOL, $EOL - 1);
            }

            return "$prefix$content$suffix";
        }
        return $content;
    }

}
