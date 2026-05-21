<?php

use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;

class IntProducingCustomFunctions
{
    public function timestamp(string $type): string|int
    {
        return $type === 'int' ? 1700000000 : '2023-11-14 22:13:20';
    }
}

class BadlyTypedCustomFunctions
{
    public function alwaysString(string $type): string
    {
        return 'not an int';
    }
}

test('mapper hint routing to a custom function works for int properties', function () {
    $generator = new ExampleGenerator(
        fakerAttributeMapper: ['_at' => 'timestamp'],
        customFunctions: ['timestamp' => [IntProducingCustomFunctions::class, 'timestamp']],
    );

    $value = $generator->created_at(['type' => 'int']);

    expect($value)->toBe(1700000000);
});

test('mapper hint routing to a custom function works for string properties', function () {
    $generator = new ExampleGenerator(
        fakerAttributeMapper: ['_at' => 'timestamp'],
        customFunctions: ['timestamp' => [IntProducingCustomFunctions::class, 'timestamp']],
    );

    $value = $generator->created_at(['type' => 'string']);

    expect($value)->toBe('2023-11-14 22:13:20');
});

test('custom function that returns mismatched type falls back to default', function () {
    $generator = new ExampleGenerator(
        fakerAttributeMapper: ['_at' => 'alwaysString'],
        customFunctions: ['alwaysString' => [BadlyTypedCustomFunctions::class, 'alwaysString']],
    );

    expect($generator->created_at(['type' => 'int']))->toBe(0);
});

test('plain Faker mapped to a non-matching property type falls back to default', function () {
    $generator = new ExampleGenerator(
        fakerAttributeMapper: ['count' => 'streetAddress'],
    );

    expect($generator->count(['type' => 'int']))->toBe(0);
});

test('plain Faker mapped to a matching string property produces a string', function () {
    $generator = new ExampleGenerator(
        fakerAttributeMapper: ['_url' => 'url'],
    );

    expect($generator->profile_url(['type' => 'string']))->toBeString()->not->toBeEmpty();
});

test('unmapped int property returns default 0 on faker miss', function () {
    $generator = new ExampleGenerator();

    expect($generator->count(['type' => 'int']))->toBe(0);
});

test('unmapped string property returns default empty string on faker miss', function () {
    $generator = new ExampleGenerator();

    expect($generator->definitely_no_such_faker(['type' => 'string']))->toBe('');
});

test('default type is treated as string', function () {
    $generator = new ExampleGenerator();

    expect($generator->somefakerthatdoesntexist([[]]))->toBe('');
});

test('faker function prefix bypasses the mapper', function () {
    $generator = new ExampleGenerator(
        fakerAttributeMapper: ['_at' => 'date'],
    );

    expect($generator->{':boolean'}(['type' => 'bool']))->toBeBool();
});
