<?php

use Langsys\OpenApiDocsGenerator\Generators\ConfigFactory;

test('deepMerge merges associative arrays recursively', function () {
    $base = [
        'dto' => [
            'paths' => ['/default/path'],
            'faker_attribute_mapper' => ['_at' => 'date'],
        ],
        'paths' => [
            'docs' => '/storage/api-docs',
        ],
    ];

    $override = [
        'dto' => [
            'paths' => ['/custom/path'],
        ],
    ];

    $result = ConfigFactory::deepMerge($base, $override);

    expect($result['dto']['paths'])->toBe(['/custom/path'])
        ->and($result['dto']['faker_attribute_mapper'])->toBe(['_at' => 'date'])
        ->and($result['paths']['docs'])->toBe('/storage/api-docs');
});

test('deepMerge replaces indexed arrays', function () {
    $base = [
        'items' => ['a', 'b', 'c'],
    ];

    $override = [
        'items' => ['x', 'y'],
    ];

    $result = ConfigFactory::deepMerge($base, $override);

    expect($result['items'])->toBe(['x', 'y']);
});

test('deepMerge replaces scalar values', function () {
    $base = [
        'enabled' => false,
        'name' => 'original',
    ];

    $override = [
        'enabled' => true,
        'name' => 'overridden',
    ];

    $result = ConfigFactory::deepMerge($base, $override);

    expect($result['enabled'])->toBeTrue()
        ->and($result['name'])->toBe('overridden');
});

test('deepMerge adds new keys from override', function () {
    $base = ['existing' => 'value'];
    $override = ['new_key' => 'new_value'];

    $result = ConfigFactory::deepMerge($base, $override);

    expect($result['existing'])->toBe('value')
        ->and($result['new_key'])->toBe('new_value');
});
