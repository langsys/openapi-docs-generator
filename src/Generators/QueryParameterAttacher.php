<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use Langsys\OpenApiDocsGenerator\Contracts\RouteResolver;
use Langsys\OpenApiDocsGenerator\Data\ResolvableOperation;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\QueryParameters;
use Langsys\OpenApiDocsGenerator\Support\OperationAction;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Psr\Log\LoggerInterface;

/**
 * Documents an operation's query parameters from the Data class its controller action
 * takes, when that class (or a parent) carries #[QueryParameters].
 *
 * The action is found as the error attacher finds it: the annotated method, or the
 * matched route's action when the annotation sits on a helper. Each public property of
 * each marked Data parameter becomes one parameter (see
 * DtoSchemaBuilder::buildQueryParameters()), appended after the hand-written ones:
 *
 *  - a property whose input name is the key of an `in: query` entry in
 *    `components.parameters` becomes a `$ref` to it, so shared parameters keep one
 *    definition; the two must agree on the name it is sent under and on whether it
 *    is required;
 *  - otherwise it is inline, and an array-typed one is named `{name}[]`, the form PHP
 *    reads a repeated query key into an array from, while an object-typed one (a
 *    string map or a nested Data class) is `deepObject`, so its keys travel as
 *    `{name}[key]`. A union keeps its plain name.
 *
 * A query parameter is never documented twice: one the operation already documents by
 * hand fails generation, and so does one two marked Data parameters (or two properties
 * of one) both read, counting a name that reads inside an object another one sends
 * (`filter[name]` and an object `filter`). So does a `$ref` whose component disagrees
 * with the property.
 */
class QueryParameterAttacher
{
    private const OPERATION_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    /** Set per attach() pass: `components.parameters` by key. */
    private array $components = [];

    public function __construct(
        private DtoSchemaBuilder $dtoSchemaBuilder,
        private ?RouteResolver $routeResolver = null,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Add query parameters to every operation whose action takes a marked Data class.
     *
     * @return int  Number of parameters added.
     *
     * @throws OpenApiDocsException when one of them is documented twice, or disagrees
     *                               with the component its `$ref` points at.
     */
    public function attach(OA\OpenApi $openapi): int
    {
        $this->components = $this->indexComponents($openapi);

        if ($openapi->paths === Generator::UNDEFINED || ! is_array($openapi->paths)) {
            return 0;
        }

        $added = 0;

        foreach ($openapi->paths as $pathItem) {
            foreach (self::OPERATION_METHODS as $method) {
                $operation = $pathItem->{$method};

                if ($operation === Generator::UNDEFINED) {
                    continue;
                }

                $added += $this->attachToOperation($operation, $pathItem, $method);
            }
        }

        if ($added > 0) {
            $this->logger?->info(sprintf('[openapi-docs] documented %d query parameter(s) from DTOs', $added));
        }

        return $added;
    }

    private function attachToOperation(OA\Operation $operation, OA\PathItem $pathItem, string $method): int
    {
        $path = $pathItem->path === Generator::UNDEFINED ? '' : (string) $pathItem->path;
        $annotatedAction = OperationAction::fromOperation($operation);
        $annotated = OperationAction::reflect($annotatedAction);
        $route = $this->routeResolver?->resolve(new ResolvableOperation(
            httpMethod: $method,
            path: $path,
            action: $annotatedAction,
        ));
        $action = OperationAction::handler($annotated, $annotatedAction, $route);

        $classes = array_values(array_filter(
            OperationAction::dataParameterClasses($action),
            static fn (string $class): bool => QueryParameters::appliesTo($class),
        ));

        if ($classes === []) {
            return 0;
        }

        $handWritten = $this->documentedQueryParameters($operation, $pathItem);
        $location = sprintf('%s@%s (%s %s)', $action->class, $action->getName(), strtoupper($method), $path);
        /** @var array<string, bool> $read  name => whether it is an object, for the names DTOs added */
        $read = [];
        /** @var array<string, class-string> $readBy  name => the Data class that added it */
        $readBy = [];
        $added = 0;

        foreach ($classes as $class) {
            foreach ($this->dtoSchemaBuilder->buildQueryParameters($class) as $parameter) {
                $name = (string) $parameter->name;
                $isObject = $this->isObject($parameter->schema);

                if (($other = $this->overlap($name, $isObject, $handWritten)) !== null) {
                    throw new OpenApiDocsException($other === $name
                        ? sprintf(
                            'Query parameter \'%s\' of %s is documented by hand and by %s; remove the hand-written one.',
                            $name,
                            $location,
                            $class,
                        )
                        : sprintf(
                            'Query parameter \'%s\' of %s, read by %s as %s, overlaps the hand-written \'%s\'%s; remove the hand-written one.',
                            $name,
                            $location,
                            $class,
                            $isObject ? sprintf('an object sent as \'%s[…]\'', $name) : 'part of an object',
                            $other,
                            $isObject ? '' : sprintf(', an object sent as \'%s[…]\'', $other),
                        ));
                }

                if (($other = $this->overlap($name, $isObject, $read)) !== null) {
                    $readers = $readBy[$other] === $class
                        ? 'properties of ' . $class
                        : sprintf('%s and %s', $readBy[$other], $class);

                    throw new OpenApiDocsException($other === $name
                        ? sprintf(
                            'Query parameter \'%s\' of %s is %s; map one of them to another input name.',
                            $name,
                            $location,
                            $readBy[$other] === $class
                                ? 'the input name of two properties of ' . $class
                                : sprintf('read by both %s and %s', $readBy[$other], $class),
                        )
                        : sprintf(
                            'Query parameter \'%s\' of %s %s; both are read by %s; map one of them to another input name.',
                            $name,
                            $location,
                            $isObject
                                ? sprintf('is an object sent as \'%s[…]\', which \'%s\' reads inside', $name, $other)
                                : sprintf('reads inside \'%s\', an object sent as \'%s[…]\'', $other, $other),
                            $readers,
                        ));
                }

                $this->assertComponentAgrees($parameter, $class, $location);
                $read[$name] = $isObject;
                $readBy[$name] = $class;

                if ($operation->parameters === Generator::UNDEFINED) {
                    $operation->parameters = [];
                }

                $operation->parameters[] = $this->finalize($parameter);
                $added++;
            }
        }

        return $added;
    }

    /**
     * A property documented by `$ref` takes the component's word on its name and on
     * whether it is required, so the two must agree: a key that matches by accident
     * would otherwise change what the endpoint documents without a trace.
     *
     * @throws OpenApiDocsException
     */
    private function assertComponentAgrees(OA\Parameter $parameter, string $class, string $location): void
    {
        $name = (string) $parameter->name;
        $component = $this->components[$name] ?? null;

        if ($component === null) {
            return;
        }

        $wireName = $this->wireName($parameter);

        if ($component->name !== $wireName) {
            throw new OpenApiDocsException(sprintf(
                'Query parameter \'%s\' of %s is read by %s as \'%s\', but #/components/parameters/%s %s; make them agree.',
                $name,
                $location,
                $class,
                $wireName,
                $name,
                Generator::isDefault($component->name) ? 'has no name' : sprintf('is named \'%s\'', $component->name),
            ));
        }

        $required = $parameter->required === true;

        if (($component->required === true) === $required) {
            return;
        }

        throw new OpenApiDocsException(sprintf(
            'Query parameter \'%s\' of %s is %s in %s but %s in #/components/parameters/%s; make them agree.',
            $name,
            $location,
            $required ? 'required' : 'optional',
            $class,
            $required ? 'optional' : 'required',
            $name,
        ));
    }

    /**
     * A `$ref` to the shared component of the same name, or the inline parameter with
     * the name and style its type is sent with.
     */
    private function finalize(OA\Parameter $parameter): OA\Parameter
    {
        $name = (string) $parameter->name;

        if (isset($this->components[$name])) {
            return new OA\Parameter(['ref' => '#/components/parameters/' . $name]);
        }

        $parameter->name = $this->wireName($parameter);

        if ($this->isObject($parameter->schema)) {
            $parameter->style = 'deepObject';
            $parameter->explode = true;
        }

        return $parameter;
    }

    /**
     * Whether a parameter's schema is an object, sent as `name[key]=…`: a string map, or
     * a nested Data class, which is an `allOf` over its component schema.
     */
    private function isObject(mixed $schema): bool
    {
        return $schema instanceof OA\Schema
            && ($schema->type === 'object' || $schema->allOf !== Generator::UNDEFINED);
    }

    /**
     * The name among $names that reads the same input as $name: $name itself, or one
     * that reads inside it (`filter[name]` inside an object `filter`), or that it reads
     * inside; null when there is none.
     *
     * @param  array<string, bool>  $names  name => whether it is an object
     */
    private function overlap(string $name, bool $isObject, array $names): ?string
    {
        if (isset($names[$name])) {
            return $name;
        }

        foreach ($names as $other => $otherIsObject) {
            if (($isObject && str_starts_with($other, $name . '['))
                || ($otherIsObject && str_starts_with($name, $other . '['))) {
                return $other;
            }
        }

        return null;
    }

    /**
     * The name a parameter is sent under: `{name}[]` for an array, the form PHP reads a
     * repeated query key into an array from (`ids=1&ids=2` would arrive as `'2'`).
     */
    private function wireName(OA\Parameter $parameter): string
    {
        $name = (string) $parameter->name;

        return $parameter->schema->type === 'array' ? $name . '[]' : $name;
    }

    /**
     * Names of the query parameters the operation and its path item already document,
     * inline or by `$ref` to a component, with any `[]` suffix removed so `ids` and
     * `ids[]` count as one parameter, each with whether it is an object (a `deepObject`).
     *
     * @return array<string, bool>
     */
    private function documentedQueryParameters(OA\Operation $operation, OA\PathItem $pathItem): array
    {
        $names = [];

        foreach ([$pathItem->parameters, $operation->parameters] as $parameters) {
            if ($parameters === Generator::UNDEFINED || ! is_array($parameters)) {
                continue;
            }

            foreach ($parameters as $parameter) {
                if ($parameter->ref !== Generator::UNDEFINED && is_string($parameter->ref)) {
                    $parameter = $this->components[substr($parameter->ref, strrpos($parameter->ref, '/') + 1)] ?? null;

                    if ($parameter === null) {
                        continue;
                    }
                }

                if ($parameter->in === 'query' && is_string($parameter->name)) {
                    $names[$this->baseName($parameter->name)] = $parameter->style === 'deepObject'
                        || $this->isObject($parameter->schema);
                }
            }
        }

        return $names;
    }

    /**
     * `components.parameters` entries that describe a query parameter, by key.
     *
     * @return array<string, OA\Parameter>
     */
    private function indexComponents(OA\OpenApi $openapi): array
    {
        if ($openapi->components === Generator::UNDEFINED
            || $openapi->components->parameters === Generator::UNDEFINED
            || ! is_array($openapi->components->parameters)) {
            return [];
        }

        $index = [];

        foreach ($openapi->components->parameters as $parameter) {
            if (is_string($parameter->parameter) && $parameter->in === 'query') {
                $index[$parameter->parameter] = $parameter;
            }
        }

        return $index;
    }

    private function baseName(string $name): string
    {
        return str_ends_with($name, '[]') ? substr($name, 0, -2) : $name;
    }
}
