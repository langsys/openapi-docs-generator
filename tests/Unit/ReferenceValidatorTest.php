<?php

use Langsys\OpenApiDocsGenerator\Generators\ReferenceValidator;

test('reports nothing for a document whose refs all resolve', function () {
    $document = [
        'paths' => [
            '/things' => [
                'get' => [
                    'responses' => [
                        '200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Thing']]]],
                    ],
                ],
            ],
        ],
        'components' => [
            'schemas' => ['Thing' => ['type' => 'object']],
        ],
    ];

    expect((new ReferenceValidator())->unresolvedRefs($document))->toBe([]);
});

test('reports a ref that points at an undefined component with its location', function () {
    $document = [
        'paths' => [
            '/translations/data' => [
                'get' => [
                    'parameters' => [
                        ['$ref' => '#/components/parameters/tier'],
                    ],
                ],
            ],
        ],
        'components' => [
            'parameters' => ['locale' => ['name' => 'locale']],
        ],
    ];

    $unresolved = (new ReferenceValidator())->unresolvedRefs($document);

    expect($unresolved)->toHaveCount(1)
        ->and($unresolved[0]['ref'])->toBe('#/components/parameters/tier')
        ->and($unresolved[0]['location'])->toBe('GET /translations/data');
});

test('resolves nested schema refs and only flags the missing one', function () {
    $document = [
        'paths' => [
            '/x' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Used']]]]]]],
        ],
        'components' => [
            'schemas' => [
                'Used' => ['allOf' => [['$ref' => '#/components/schemas/Nested'], ['$ref' => '#/components/schemas/Ghost']]],
                'Nested' => ['type' => 'object'],
            ],
        ],
    ];

    $unresolved = (new ReferenceValidator())->unresolvedRefs($document);

    expect(array_column($unresolved, 'ref'))->toBe(['#/components/schemas/Ghost']);
});

test('flags a discriminator mapping target that is not defined', function () {
    $document = [
        'components' => [
            'schemas' => [
                'Pet' => [
                    'discriminator' => [
                        'propertyName' => 'type',
                        'mapping' => ['cat' => '#/components/schemas/Cat', 'dog' => 'Dog'],
                    ],
                ],
                'Cat' => ['type' => 'object'],
                // Dog intentionally missing
            ],
        ],
    ];

    $unresolved = (new ReferenceValidator())->unresolvedRefs($document);

    expect(array_column($unresolved, 'ref'))->toContain('#/components/schemas/Dog')
        ->and(array_column($unresolved, 'ref'))->not->toContain('#/components/schemas/Cat');
});

test('ignores external (non-local) refs', function () {
    $document = [
        'paths' => [
            '/x' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => 'https://example.com/schemas/Thing.json']]]]]]],
        ],
    ];

    expect((new ReferenceValidator())->unresolvedRefs($document))->toBe([]);
});
