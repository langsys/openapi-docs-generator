<?php

namespace Langsys\SwaggerAutoGenerator\Generators\Swagger;

use Illuminate\Support\Str;

class ExampleGenerator
{

    const FAKER_FUNCTION_PREFIX = ':';
    const SINGLE_QUOTE_IDENTIFIER = '#[SINGLE_QUOTE]';

    // Variable will only need to contain any of these keys so the function is called

    private $faker;
    private $customFunctions;

    public function __construct()
    {
        $this->faker = fake();
        $this->customFunctions = config('langsys-generator.custom_functions');
    }


    public function __call($name, $arguments): string|int
    {
        [$arguments] = $arguments;
        $propertyType = $arguments['type'] ?? 'string';

        $function = $this->_getExampleFunction($name);

        // If $function is a string, handle it as a method on this class or a Faker method
        if (is_string($function) && isset($this->customFunctions[$function])) {
            [$class] = $this->customFunctions[$function];
            return app($class)->$function(...array_values($arguments));
        }

        try {
            unset($arguments['type']);
            $camelCaseName = Str::camel($function);

            $example = $this->faker->$camelCaseName(...$arguments);
            $example = is_array($example) ? array_pop($example) : $example;

            return str_replace(["'", '"'], [self::SINGLE_QUOTE_IDENTIFIER, ''], $example);
        } catch (\Exception $e) {
            return $propertyType === 'int' ? 0 : '';
        }
    }

    private function _getExampleFunction(string $name): string
    {
        // Fallback to default function handling
        if (str_starts_with($name, self::FAKER_FUNCTION_PREFIX)) {
            $functionName = str_replace(self::FAKER_FUNCTION_PREFIX, '', $name);
            return $functionName;
        }

        // Check the attribute mapping for default or extended configurations
        $mapping = config('langsys-generator.faker_attribute_mapper');

        foreach ($mapping as $hint => $function) {
            if (str_contains($name, $hint)) {
                return $function;
            }
        }

        return $name;
    }


    private function isCustomFunction($function): bool
    {
        //Is array and exists in the config file,
        return is_array($function) && count($function) === 2 && array_key_exists($function[1], config('langsys-generator.custom_functions'));
    }


}

