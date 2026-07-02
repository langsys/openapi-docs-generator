<?php

use Langsys\OpenApiDocsGenerator\Contracts\OperationFilter;
use Langsys\OpenApiDocsGenerator\Data\OperationContext;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use Langsys\OpenApiDocsGenerator\Filters\MiddlewareFilter;
use Langsys\OpenApiDocsGenerator\Filters\OperationFilterFactory;
use Langsys\OpenApiDocsGenerator\Filters\OperationIdFilter;
use Langsys\OpenApiDocsGenerator\Filters\PathFilter;
use Langsys\OpenApiDocsGenerator\Filters\TagFilter;

class FactoryConfigurableFilter implements OperationFilter
{
    public array $args;

    public function __construct(...$args)
    {
        $this->args = $args;
    }

    public function matches(OperationContext $context): bool
    {
        return true;
    }
}

class FactoryNotAFilter {}

test('builds a MiddlewareFilter from a middleware descriptor', function () {
    $filter = (new OperationFilterFactory(['auth.apikey' => 'X']))->make(['middleware' => 'auth.apikey']);

    expect($filter)->toBeInstanceOf(MiddlewareFilter::class);
});

test('builds a TagFilter, PathFilter and OperationIdFilter from their descriptors', function () {
    $factory = new OperationFilterFactory();

    expect($factory->make(['tag' => 'Public']))->toBeInstanceOf(TagFilter::class)
        ->and($factory->make(['path' => 'webhooks/*']))->toBeInstanceOf(PathFilter::class)
        ->and($factory->make(['operationId' => 'listProjects']))->toBeInstanceOf(OperationIdFilter::class);
});

test('builds a custom filter via the class escape hatch and passes args', function () {
    $filter = (new OperationFilterFactory())->make([
        'class' => FactoryConfigurableFilter::class,
        'args' => ['one', 'two'],
    ]);

    expect($filter)->toBeInstanceOf(FactoryConfigurableFilter::class)
        ->and($filter->args)->toBe(['one', 'two']);
});

test('throws when the custom class does not implement OperationFilter', function () {
    expect(fn () => (new OperationFilterFactory())->make(['class' => FactoryNotAFilter::class]))
        ->toThrow(OpenApiDocsException::class);
});

test('throws when the custom class does not exist', function () {
    expect(fn () => (new OperationFilterFactory())->make(['class' => 'Nope\\Missing']))
        ->toThrow(OpenApiDocsException::class);
});

test('throws on an unrecognized descriptor', function () {
    expect(fn () => (new OperationFilterFactory())->make(['nonsense' => true]))
        ->toThrow(OpenApiDocsException::class);
});

test('makeMany builds a filter per descriptor', function () {
    $filters = (new OperationFilterFactory(['auth.apikey' => 'X']))->makeMany([
        ['middleware' => 'auth.apikey'],
        ['tag' => 'Public'],
    ]);

    expect($filters)->toHaveCount(2)
        ->and($filters[0])->toBeInstanceOf(MiddlewareFilter::class)
        ->and($filters[1])->toBeInstanceOf(TagFilter::class);
});
