<?php

namespace Langsys\SwaggerAutoGenerator\Generators\Swagger;

use Illuminate\Support\Str;

class ExampleGenerator
{

    const FAKER_FUNCTION_PREFIX = ':';
    const SINGLE_QUOTE_IDENTIFIER = '#[SINGLE_QUOTE]';

    // Variable will only need to contain any of these keys so the function is called

    private \Faker\Generator $faker;

    public function __construct()
    {
        $this->faker = fake();
    }


    public function __call($name, $arguments): string|int
    {
        [$arguments] = $arguments;
        $propertyType = $arguments['type'] ?? 'string';

        $function = $this->_getExampleFunction($name);

        if ($function && method_exists($this, $function)) {
            return $this->$function(...array_values($arguments));
        }

        try {
            unset($arguments['type']);
            $camelCaseName = Str::camel($name);
            $example = $function ? $this->faker->$function(...$arguments) : $this->faker->$camelCaseName(...$arguments);
            $example = is_array($example) ? array_pop($example) : $example;

            return str_replace(["'", '"'], [self::SINGLE_QUOTE_IDENTIFIER, ''], $example);
        } catch (\Exception $e) {
            return $propertyType === 'int' ? 0 : '';
        }
    }

    private function _getExampleFunction(string $name): callable|bool
    {
        if (str_starts_with($name, self::FAKER_FUNCTION_PREFIX)) {
            $functionName = str_replace(self::FAKER_FUNCTION_PREFIX, '', $name);
            return [$this->faker, $functionName];
        }

        $mapping = config('langsys-generator.faker_attribute_mapper');
        foreach ($mapping as $hint => $function) {
            if (str_contains($name, $hint)) {
                if (is_array($function)) {
                    return $function;  // Directly return the callable array
                }
                return [$this, $function];
            }
        }

        return false;
    }


}

