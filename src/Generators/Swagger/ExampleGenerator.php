<?php

namespace Langsys\SwaggerAutoGenerator\Generators\Swagger;


use App\Models\Locale;
use Faker\Factory;
use Illuminate\Support\Str;
use Langsys\SwaggerAutoGenerator\Generators\Swagger\Traits\HasUserFunctions;

class ExampleGenerator
{
    use HasUserFunctions;

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

    private function _getExampleFunction(string $name): string|bool
    {
        if (str_starts_with($name, self::FAKER_FUNCTION_PREFIX)) {
            return str_replace(self::FAKER_FUNCTION_PREFIX, '', $name);
        }
        foreach (config('langsys-generator.faker_attribute_mapper') as $hint => $function) {
            if (str_contains($name, $hint)) {
                return $function;
            }
        }

        return false;
    }


}

