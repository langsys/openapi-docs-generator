<?php

use Langsys\OpenApiDocsGenerator\Generators\ComponentTagPruner;
use OpenApi\Annotations as OA;
use OpenApi\Generator;

function schemaRef(string $name): OA\Schema
{
    return new OA\Schema(['ref' => '#/components/schemas/' . $name]);
}

function objectSchema(string $name, array $refProps = []): OA\Schema
{
    $properties = [];
    foreach ($refProps as $propName => $target) {
        $properties[] = new OA\Property([
            'property' => $propName,
            'allOf' => [schemaRef($target)],
        ]);
    }

    return new OA\Schema([
        'schema' => $name,
        'type' => 'object',
        'properties' => $properties === [] ? [new OA\Property(['property' => 'id', 'type' => 'integer'])] : $properties,
    ]);
}

function securityScheme(string $name): OA\SecurityScheme
{
    return new OA\SecurityScheme([
        'securityScheme' => $name,
        'type' => 'apiKey',
        'name' => 'X-Api-Key',
        'in' => 'header',
    ]);
}

function modelForPruning(array $operationSecurity = [['apiKey' => []]], array $globalSecurity = []): OA\OpenApi
{
    $response = new OA\Response(['response' => '200', 'description' => 'OK']);
    $response->content = [
        new OA\MediaType(['mediaType' => 'application/json', 'schema' => schemaRef('Used')]),
    ];

    $operation = new OA\Get(['responses' => [$response]]);
    $operation->tags = ['Kept', 'AlsoKept'];
    $operation->security = $operationSecurity;

    $pathItem = new OA\PathItem(['path' => '/api/x']);
    $pathItem->get = $operation;

    $components = new OA\Components([]);
    $components->schemas = [
        objectSchema('Used', ['nested' => 'NestedUsed']),
        objectSchema('NestedUsed'),
        objectSchema('Orphan'),
        // A cycle unreachable from any operation — must still be pruned.
        objectSchema('CycleA', ['b' => 'CycleB']),
        objectSchema('CycleB', ['a' => 'CycleA']),
    ];
    $components->securitySchemes = [securityScheme('apiKey'), securityScheme('bearerAuth')];

    $openapi = new OA\OpenApi(['info' => new OA\Info(['title' => 'T', 'version' => '1.0'])]);
    $openapi->paths = [$pathItem];
    $openapi->components = $components;
    $openapi->tags = [
        new OA\Tag(['name' => 'Zeta', 'description' => 'z']),
        new OA\Tag(['name' => 'AlsoKept', 'description' => 'also kept desc']),
        new OA\Tag(['name' => 'Kept', 'description' => 'kept desc']),
        new OA\Tag(['name' => 'Unused', 'description' => 'u']),
    ];

    if ($globalSecurity !== []) {
        $openapi->security = $globalSecurity;
    }

    return $openapi;
}

function schemaNames(OA\OpenApi $openapi): array
{
    if ($openapi->components->schemas === Generator::UNDEFINED) {
        return [];
    }

    return array_map(fn (OA\Schema $s) => $s->schema, $openapi->components->schemas);
}

test('keeps the transitive closure of referenced schemas and drops orphans and cycles', function () {
    $openapi = modelForPruning();

    (new ComponentTagPruner())->prune($openapi);

    $names = schemaNames($openapi);
    expect($names)->toContain('Used')
        ->and($names)->toContain('NestedUsed')
        ->and($names)->not->toContain('Orphan')
        ->and($names)->not->toContain('CycleA')
        ->and($names)->not->toContain('CycleB');
});

test('prunes security schemes not referenced by any surviving operation security', function () {
    $openapi = modelForPruning(operationSecurity: [['apiKey' => []]]);

    (new ComponentTagPruner())->prune($openapi);

    $schemeNames = array_map(fn (OA\SecurityScheme $s) => $s->securityScheme, $openapi->components->securitySchemes);
    expect($schemeNames)->toBe(['apiKey']);
});

test('recognizes global security requirements when pruning schemes', function () {
    $openapi = modelForPruning(operationSecurity: [], globalSecurity: [['bearerAuth' => []]]);

    (new ComponentTagPruner())->prune($openapi);

    $schemeNames = array_map(fn (OA\SecurityScheme $s) => $s->securityScheme, $openapi->components->securitySchemes);
    expect($schemeNames)->toBe(['bearerAuth']);
});

test('keeps only used tags, preserving their object and original order', function () {
    $openapi = modelForPruning();

    (new ComponentTagPruner())->prune($openapi);

    $tags = $openapi->tags;
    expect(array_map(fn (OA\Tag $t) => $t->name, $tags))->toBe(['AlsoKept', 'Kept'])
        ->and($tags[0]->description)->toBe('also kept desc')
        ->and($tags[1]->description)->toBe('kept desc');
});

test('does nothing harmful when there are no components', function () {
    $openapi = new OA\OpenApi(['info' => new OA\Info(['title' => 'T', 'version' => '1.0'])]);
    $pathItem = new OA\PathItem(['path' => '/api/x']);
    $pathItem->get = new OA\Get(['responses' => [new OA\Response(['response' => '200', 'description' => 'OK'])]]);
    $openapi->paths = [$pathItem];

    (new ComponentTagPruner())->prune($openapi);

    expect($openapi->components)->toBe(Generator::UNDEFINED);
});
