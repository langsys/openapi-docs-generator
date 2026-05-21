<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use Illuminate\Support\Str;

class ExampleGenerator
{

    const FAKER_FUNCTION_PREFIX = ':';
    const SINGLE_QUOTE_IDENTIFIER = '#[SINGLE_QUOTE]';

    private $faker;
    private array $fakerAttributeMapper;
    private array $customFunctions;

    public function __construct(
        array $fakerAttributeMapper = [],
        array $customFunctions = [],
    ) {
        $this->faker = fake();
        $this->fakerAttributeMapper = $fakerAttributeMapper;
        $this->customFunctions = $customFunctions;
    }


    public function __call($name, $arguments): string|int|float|bool
    {
        [$arguments] = $arguments;
        $propertyType = $arguments['type'] ?? 'string';

        $function = $this->_getExampleFunction($name);

        try {
            if (is_string($function) && isset($this->customFunctions[$function])) {
                [$class] = $this->customFunctions[$function];
                $example = app($class)->$function(...array_values($arguments));
            } else {
                unset($arguments['type']);
                $example = $this->faker->{Str::camel($function)}(...$arguments);
                $example = is_array($example) ? array_pop($example) : $example;
                if (is_string($example)) {
                    $example = str_replace(["'", '"'], [self::SINGLE_QUOTE_IDENTIFIER, ''], $example);
                }
            }
        } catch (\Exception) {
            return $this->_defaultFor($propertyType);
        }

        return $this->_matchesPropertyType($example, $propertyType)
            ? $example
            : $this->_defaultFor($propertyType);
    }

    private function _getExampleFunction(string $name): string
    {
        if (str_starts_with($name, self::FAKER_FUNCTION_PREFIX)) {
            return str_replace(self::FAKER_FUNCTION_PREFIX, '', $name);
        }

        // Match hints as whole words (underscore-delimited) or suffixes.
        // Hints starting with `_` are treated as suffix matches (e.g. `_at`, `_id`).
        // Other hints must match as a whole word: the full name, or a segment between underscores.
        foreach ($this->fakerAttributeMapper as $hint => $function) {
            if (str_starts_with($hint, '_')) {
                if (str_ends_with($name, $hint)) {
                    return $function;
                }
                continue;
            }

            if (in_array($hint, explode('_', $name), true)) {
                return $function;
            }
        }

        return $name;
    }

    private function _matchesPropertyType(mixed $value, string $propertyType): bool
    {
        return match ($propertyType) {
            'int'    => is_int($value),
            'float'  => is_float($value) || is_int($value),
            'bool'   => is_bool($value),
            default  => is_string($value),
        };
    }

    private function _defaultFor(string $propertyType): string|int|float|bool
    {
        return match ($propertyType) {
            'int'    => 0,
            'float'  => 0.0,
            'bool'   => false,
            default  => '',
        };
    }
}
