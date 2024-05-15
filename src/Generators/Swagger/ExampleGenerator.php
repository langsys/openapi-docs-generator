<?php

namespace Langsys\SwaggerAutoGenerator\Generators\Swagger;

use Illuminate\Support\Str;

class ExampleGenerator
{

    const FAKER_FUNCTION_PREFIX = ':';
    const SINGLE_QUOTE_IDENTIFIER = '#[SINGLE_QUOTE]';

    // Variable will only need to contain any of these keys so the function is called

    private $faker;

    public function __construct()
    {
        $this->faker = fake();
    }


    public function __call($name, $arguments): string|int
    {
        [$arguments] = $arguments;
        $propertyType = $arguments['type'] ?? 'string';

        $function = $this->_getExampleFunction($name);

        // If $function is an array, handle it as a custom function
        if ($function && is_array($function)) {
            [$class, $method] = $function;
            return app($class)->$method(...array_values($arguments));
        }

        // If $function is a string, handle it as a method on this class or a Faker method
        if ($function && is_string($function)) {
            if (method_exists($this, $function)) {
                return $this->$function(...array_values($arguments));
            }
        }

        try {
            unset($arguments['type']);
            $camelCaseName = Str::camel($name);

            $example = $this->faker->$camelCaseName(...$arguments);
            $example = is_array($example) ? array_pop($example) : $example;

            return str_replace(["'", '"'], [self::SINGLE_QUOTE_IDENTIFIER, ''], $example);
        } catch (\Exception $e) {
            return $propertyType === 'int' ? 0 : '';
        }
    }

    private function _getExampleFunction(string $name): callable|bool|array
    {
        // Check for custom function definitions first
        $customFunctions = config('langsys-generator.custom_functions');
        if (array_key_exists($name, $customFunctions)) {
            return $customFunctions[$name];  // Directly use the custom function if specified
        }

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

        return false;
    }


    private function isCustomFunction($function): bool
    {
        //Is array and exists in the config file,
        return is_array($function) && count($function) === 2 && array_key_exists($function[1], config('langsys-generator.custom_functions'));
    }


}

