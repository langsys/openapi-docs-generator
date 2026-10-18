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
 *    definition; the two must agree on the parameter's name and on whether it is
 *    required;
 *  - otherwise it is inline, and an array-typed one is named `{name}[]`, the form PHP
 *    reads a repeated query key into an array from, while an object-typed one (a
 *    string map or a nested Data class) is `deepObject`, so its keys travel as
 *    `{name}[key]`. A union keeps its plain name.
 *
 * A query parameter is never documented twice: one the operation already documents by
 * hand fails generation, and so does one two marked Data parameters (or two properties
 * of one) both read. So does a `$ref` whose component disagrees with the property.
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
        /** @var array<string, class-string> $readBy  name => the Data class that added it */
        $readBy = [];
        $added = 0;

        foreach ($classes as $class) {
            foreach ($this->dtoSchemaBuilder->buildQueryParameters($class) as $parameter) {
                $name = (string) $parameter->name;

                if (isset($handWritten[$name])) {
                    throw new OpenApiDocsException(sprintf(
                        'Query parameter \'%s\' of %s is documented by hand and by %s; remove the hand-written one.',
                        $name,
                        $location,
                        $class,
                    ));
                }

                if (isset($readBy[$name])) {
                    throw new OpenApiDocsException(sprintf(
                        'Query parameter \'%s\' of %s is %s; map one of them to another input name.',
                        $name,
                        $location,
                        $readBy[$name] === $class
                            ? 'the input name of two properties of ' . $class
                            : sprintf('read by both %s and %s', $readBy[$name], $class),
                    ));
                }

                $this->assertComponentAgrees($parameter, $class, $location);
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

        if (is_string($component->name) && $this->baseName($component->name) !== $name) {
            throw new OpenApiDocsException(sprintf(
                'Query parameter \'%s\' of %s is read by %s, but #/components/parameters/%s is named \'%s\'; make them agree.',
                $name,
                $location,
                $class,
                $name,
                $component->name,
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

        // A nested Data class is an `allOf` over its component schema.
        if ($parameter->schema->type === 'array') {
            $parameter->name = $name . '[]';
        } elseif ($parameter->schema->type === 'object' || $parameter->schema->allOf !== Generator::UNDEFINED) {
            $parameter->style = 'deepObject';
            $parameter->explode = true;
        }

        return $parameter;
    }

    /**
     * Names of the query parameters the operation and its path item already document,
     * inline or by `$ref` to a component, with any `[]` suffix removed so `ids` and
     * `ids[]` count as one parameter.
     *
     * @return array<string, true>
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
                    $names[$this->baseName($parameter->name)] = true;
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
