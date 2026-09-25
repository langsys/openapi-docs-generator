<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Langsys\OpenApiDocsGenerator\Contracts\ImpliedErrorRule;
use Langsys\OpenApiDocsGenerator\Contracts\RouteResolver;
use Langsys\OpenApiDocsGenerator\Contracts\ValidationScenarioResolver;
use Langsys\OpenApiDocsGenerator\Data\ErrorDefinition;
use Langsys\OpenApiDocsGenerator\Data\OperationContext;
use Langsys\OpenApiDocsGenerator\Data\ResolvableOperation;
use Langsys\OpenApiDocsGenerator\Data\ResolvedRoute;
use Langsys\OpenApiDocsGenerator\Data\ValidationScenario;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use Langsys\OpenApiDocsGenerator\Filters\MiddlewareFilter;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Throws;
use Langsys\OpenApiDocsGenerator\Support\OperationAction;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Spatie\LaravelData\Data;

/**
 * Attaches documented error responses to operations, so the same 401/422/404
 * blocks don't have to be hand-typed on every action.
 *
 * An operation's errors come from three sources, unioned and deduplicated by class:
 *
 *  1. `#[Throws(...)]` on the backing controller action — the explicit list.
 *  2. `implied_errors.middleware` — errors every route carrying a given middleware
 *     can return, matched against the route's fully-resolved middleware (the same
 *     ground truth filtered sets use, via {@see MiddlewareFilter}).
 *  3. Structural rules — an action taking a Spatie Data parameter implies the
 *     configured validation error; a route with a bound `{param}` implies the
 *     configured not-found error.
 *  4. `implied_errors.rules` — {@see ImpliedErrorRule} implementations the app
 *     owns, for conventions the framework cannot prove (an in-body authorization
 *     call, a permission registry). The library ships no such rule: a heuristic
 *     over an implementation idiom belongs with the app that owns the idiom.
 *
 * The errors are then grouped by HTTP status. A status with one error becomes a
 * `$ref` to its reusable `components.responses.{Name}`; a status shared by several
 * becomes an inline response whose `error` property is a `oneOf` of their error
 * bodies with `code` as the discriminator. A response the author wrote for that status always
 * wins — the same precedence rule DTO schemas follow.
 */
class OperationErrorAttacher
{
    private const OPERATION_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    /** @var array<string, ErrorDefinition> class name => definition */
    private array $definitions = [];

    /** @var array<int, array{filter: MiddlewareFilter, classes: array<int, string>}> */
    private array $middlewareRules = [];

    /** @var array<int, string> */
    private array $validationErrors;

    /** @var array<int, string> */
    private array $notFoundErrors;

    /** 'model' (default) — only model-bound params; 'any' — any `{param}`. */
    private string $notFoundBinding;

    /** @var array<int, ImpliedErrorRule> */
    private array $rules;

    /** @var array<int, ValidationScenarioResolver> */
    private array $scenarioResolvers;

    /** Set per attach() pass: the shape the referenced error schemas were built with. */
    private ErrorEnvelope $envelope;

    /** Set per attach() pass: the contract, for precise messages about undocumented classes. */
    private ?ErrorContract $contract = null;

    /** Set per attach() pass: serialized component schemas by name, for building example bodies. */
    private array $schemaIndex = [];

    /**
     * @param  RouteResolver|null  $routeResolver  Needed for middleware and not-found rules;
     *                                             without it only `#[Throws]` and the
     *                                             validation rule apply.
     * @param  array<string, mixed>  $impliedErrors  The set's `implied_errors` config.
     * @param  array<string, string>  $aliasMap  Router middleware alias map (alias => class).
     * @param  Router|null  $router  Used to detect explicit `Route::bind()` parameters.
     */
    public function __construct(
        private ?RouteResolver $routeResolver = null,
        array $impliedErrors = [],
        array $aliasMap = [],
        private ?Router $router = null,
        private ?LoggerInterface $logger = null,
    ) {
        foreach (($impliedErrors['middleware'] ?? []) as $middleware => $classes) {
            $classes = array_values((array) $classes);
            if ($classes === []) {
                continue;
            }

            $this->middlewareRules[] = [
                'filter' => new MiddlewareFilter($middleware, $aliasMap),
                'classes' => $classes,
            ];
        }

        $this->validationErrors = array_values((array) ($impliedErrors['validation'] ?? []));
        $this->notFoundErrors = array_values((array) ($impliedErrors['not_found'] ?? []));
        $this->notFoundBinding = (string) ($impliedErrors['not_found_binding'] ?? 'model');
        $this->rules = (new ImpliedErrorRuleFactory())->makeMany((array) ($impliedErrors['rules'] ?? []));
        $this->scenarioResolvers = (new ValidationScenarioResolverFactory())->makeMany($impliedErrors['validation_scenarios'] ?? []);
    }

    /**
     * Attach error responses to every operation in the document.
     *
     * @param  array<int, ErrorDefinition>  $errorDefinitions  Errors discovered by the DTO
     *         builder, indexed here for the duration of this pass.
     * @param  ErrorEnvelope|null  $envelope  The shape those errors' schemas were built with;
     *         shared-status responses are built from it so they reference them correctly.
     *         Defaults to the default envelope.
     * @param  ErrorContract|null  $contract  The contract those errors were discovered with;
     *         lets a failure say whether a declared class is not an error class or was
     *         never scanned. Without it the message covers both.
     * @return int  Number of responses added.
     * @throws OpenApiDocsException when a declared error class isn't a documented error.
     */
    public function attach(OA\OpenApi $openapi, array $errorDefinitions, ?ErrorEnvelope $envelope = null, ?ErrorContract $contract = null): int
    {
        $this->envelope = $envelope ?? new ErrorEnvelope();
        $this->contract = $contract;
        $this->definitions = [];
        foreach ($errorDefinitions as $definition) {
            $this->definitions[$definition->className] = $definition;
        }

        $this->schemaIndex = $this->indexSchemas($openapi);

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
            $this->logger?->info(sprintf('[openapi-docs] attached %d error response(s) to operations', $added));
        }

        return $added;
    }

    private function attachToOperation(OA\Operation $operation, OA\PathItem $pathItem, string $method): int
    {
        $path = $pathItem->path === Generator::UNDEFINED ? '' : (string) $pathItem->path;
        $annotatedAction = OperationAction::fromOperation($operation);
        $annotated = OperationAction::reflect($annotatedAction);
        $route = $this->resolveRoute($method, $path, $annotatedAction);
        $action = $this->actionFor($annotated, $annotatedAction, $route);

        $context = new OperationContext(
            operation: $operation,
            pathItem: $pathItem,
            httpMethod: $method,
            path: $path,
            route: $route,
        );

        $classes = $this->errorClassesFor($context, $annotated, $action);
        $scenarios = $this->scenariosFor($context, $action);

        if ($scenarios !== []) {
            if ($this->validationErrors === []) {
                throw new OpenApiDocsException(sprintf(
                    'A validation scenario resolver returned scenarios for %s, but no implied_errors.validation '
                    . 'error class is configured to attach them to.',
                    strtoupper($method) . ' ' . $path,
                ));
            }

            $classes = array_values(array_unique(array_merge($classes, $this->validationErrors)));
        }

        if ($classes === []) {
            return 0;
        }

        $scenarioStatuses = [];
        foreach ($scenarios === [] ? [] : $this->validationErrors as $validationClass) {
            $scenarioStatuses[$this->definitionFor($validationClass, $method, $path)->status] = true;
        }

        $byStatus = [];
        foreach ($classes as $class) {
            $definition = $this->definitionFor($class, $method, $path);
            $byStatus[$definition->status][$definition->code] = $definition;
        }

        ksort($byStatus);

        $documented = $this->documentedStatuses($operation);
        $added = 0;

        foreach ($byStatus as $status => $definitions) {
            if (isset($documented[(string) $status])) {
                continue; // A hand-written response for this status wins.
            }

            ksort($definitions);

            if ($operation->responses === Generator::UNDEFINED) {
                $operation->responses = [];
            }

            $operation->responses[] = $this->buildResponse(
                $status,
                array_values($definitions),
                isset($scenarioStatuses[$status]) ? $scenarios : [],
            );
            $added++;
        }

        return $added;
    }

    /**
     * Serialize the document's component schemas by name, so an example body can be
     * built from the examples those schemas already advertise.
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexSchemas(OA\OpenApi $openapi): array
    {
        if ($openapi->components === Generator::UNDEFINED
            || $openapi->components->schemas === Generator::UNDEFINED
            || ! is_array($openapi->components->schemas)) {
            return [];
        }

        $index = [];

        foreach ($openapi->components->schemas as $schema) {
            $name = $schema->schema ?? Generator::UNDEFINED;

            if (is_string($name) && $name !== '' && $name !== Generator::UNDEFINED) {
                $index[$name] = json_decode(json_encode($schema), true);
            }
        }

        return $index;
    }

    /**
     * An example error object for one error, taken from the examples its `{Name}Body`
     * schema already advertises: the filled message, the code, the template, its params
     * and its typed details. A property with no example of its own is left out rather
     * than invented, so an app-owned envelope field (a validation `errors` map, say)
     * never appears with made-up content.
     *
     * @return array<string, mixed>
     */
    private function errorObjectExample(ErrorDefinition $definition): array
    {
        $body = $this->schemaIndex[$definition->bodySchemaName] ?? null;
        $example = $body === null ? null : $this->exampleFromSchema($body, []);

        return is_array($example) && $example !== []
            ? $example
            : $this->envelope->exampleErrorObject($definition);
    }

    /**
     * The example value a serialized schema describes: its own `example`, a one-item
     * list of its items' example, an object of its properties' examples, or the example
     * of what it references.
     *
     * An array property carries its example on `items`, the only place a scalar
     * `#[Example]` can sit, so a list of one is what the schema actually advertises.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<int, string>  $seen  Refs already followed, so a cycle can't recurse forever.
     */
    private function exampleFromSchema(array $schema, array $seen): mixed
    {
        if (array_key_exists('example', $schema)) {
            return $schema['example'];
        }

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $ref = $schema['$ref'];

            if (in_array($ref, $seen, true)) {
                return null;
            }

            $name = substr($ref, strrpos($ref, '/') + 1);
            $target = $this->schemaIndex[$name] ?? null;

            return $target === null ? null : $this->exampleFromSchema($target, [...$seen, $ref]);
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $merged = [];

            foreach ($schema['allOf'] as $part) {
                $value = is_array($part) ? $this->exampleFromSchema($part, $seen) : null;

                if (is_array($value)) {
                    $merged = [...$merged, ...$value];
                }
            }

            return $merged === [] ? null : $merged;
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $item = $this->exampleFromSchema($schema['items'], $seen);

            return $item === null ? null : [$item];
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $object = [];

            foreach ($schema['properties'] as $name => $property) {
                $value = is_array($property) ? $this->exampleFromSchema($property, $seen) : null;

                if ($value !== null) {
                    $object[$name] = $value;
                }
            }

            return $object === [] ? null : $object;
        }

        return null;
    }

    /**
     * The method that actually handles the operation. Normally the annotated method,
     * but an @OA annotation can sit on a helper rather than on the route's action; when
     * the matched route names a different, reflectable controller action, that action
     * is the one rules and structural checks must inspect.
     */
    private function actionFor(?ReflectionMethod $annotated, ?string $annotatedAction, ?ResolvedRoute $route): ?ReflectionMethod
    {
        $routeAction = $route?->action();

        if ($routeAction === null || $routeAction === $annotatedAction) {
            return $annotated;
        }

        return OperationAction::reflect($routeAction) ?? $annotated;
    }

    /**
     * Union of the operation's declared and implied error classes, deduplicated,
     * in a stable order (declared first, then middleware, then structural, then rules).
     *
     * `#[Throws]` is read from both the annotated method and the route's action, since
     * authors put it on either; everything else inspects the route's action.
     *
     * @return array<int, string>
     */
    private function errorClassesFor(OperationContext $context, ?ReflectionMethod $annotated, ?ReflectionMethod $reflection): array
    {
        $classes = $this->declaredErrors($annotated);

        if ($reflection !== null && ($annotated === null || $this->actionName($reflection) !== $this->actionName($annotated))) {
            $classes = array_merge($classes, $this->declaredErrors($reflection));
        }

        if ($context->route !== null) {
            foreach ($this->middlewareRules as $rule) {
                if ($rule['filter']->matches($context)) {
                    $classes = array_merge($classes, $rule['classes']);
                }
            }
        }

        if ($this->validationErrors !== [] && $this->acceptsDataParameter($reflection)) {
            $classes = array_merge($classes, $this->validationErrors);
        }

        if ($this->notFoundErrors !== [] && $this->hasBoundParameter($context->route, $reflection)) {
            $classes = array_merge($classes, $this->notFoundErrors);
        }

        foreach ($this->rules as $rule) {
            $classes = array_merge($classes, array_values($rule->errorsFor($context, $reflection)));
        }

        return array_values(array_unique($classes));
    }

    private function actionName(ReflectionMethod $method): string
    {
        return $method->getDeclaringClass()->getName() . '@' . $method->getName();
    }

    /**
     * The validation failures every configured resolver reports for this operation,
     * deduplicated on the exact field/code/message triple, first occurrence winning.
     * Not on field plus code: several rules legitimately share one code with different
     * messages (an `invalid_option` that is "not a target locale" in one rule and "not
     * supported" in another), and keying on the code alone would show only one of them.
     *
     * @return array<int, ValidationScenario>
     *
     * @throws OpenApiDocsException when a resolver returns something else.
     */
    private function scenariosFor(OperationContext $context, ?ReflectionMethod $action): array
    {
        $scenarios = [];

        foreach ($this->scenarioResolvers as $resolver) {
            foreach ($resolver->scenariosFor($context, $action) as $scenario) {
                if (! $scenario instanceof ValidationScenario) {
                    throw new OpenApiDocsException(sprintf(
                        '%s::scenariosFor() must return %s instances; got %s.',
                        $resolver::class,
                        ValidationScenario::class,
                        get_debug_type($scenario),
                    ));
                }

                $key = ($scenario->field ?? '') . "\0" . $scenario->code . "\0" . $scenario->message;

                if (! isset($scenarios[$key])) {
                    $scenarios[$key] = $scenario;
                }
            }
        }

        return array_values($scenarios);
    }

    /**
     * Error classes named by `#[Throws]` on the action (or on its class, applying
     * to every action of that controller).
     *
     * @return array<int, string>
     */
    private function declaredErrors(?ReflectionMethod $reflection): array
    {
        if ($reflection === null) {
            return [];
        }

        $classes = [];

        foreach ($reflection->getDeclaringClass()->getAttributes(Throws::class) as $attribute) {
            $classes = array_merge($classes, $attribute->newInstance()->errorClasses);
        }

        foreach ($reflection->getAttributes(Throws::class) as $attribute) {
            $classes = array_merge($classes, $attribute->newInstance()->errorClasses);
        }

        return $classes;
    }

    /**
     * Whether the action takes a Spatie Data parameter — i.e. the request is
     * validated by Laravel Data, so it can fail validation.
     */
    private function acceptsDataParameter(?ReflectionMethod $reflection): bool
    {
        if ($reflection === null) {
            return false;
        }

        foreach ($reflection->getParameters() as $parameter) {
            foreach ($this->namedTypes($parameter->getType()) as $type) {
                $name = $type->getName();
                if (! $type->isBuiltin() && (is_a($name, Data::class, true))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the route has a `{param}` that resolves to a record — either bound
     * to an Eloquent model (implicitly by the action's signature, or explicitly by
     * `Route::bind()`), or any parameter at all when
     * `implied_errors.not_found_binding` is 'any'.
     */
    private function hasBoundParameter(?ResolvedRoute $route, ?ReflectionMethod $reflection): bool
    {
        if ($route === null) {
            return false;
        }

        $parameters = $this->routeParameterNames($route->uri());

        if ($parameters === []) {
            return false;
        }

        if ($this->notFoundBinding === 'any') {
            return true;
        }

        foreach ($parameters as $parameter) {
            if ($this->router?->getBindingCallback($parameter) !== null) {
                return true; // explicit Route::bind()
            }

            if ($reflection === null) {
                continue;
            }

            foreach ($reflection->getParameters() as $actionParameter) {
                if ($actionParameter->getName() !== $parameter) {
                    continue;
                }

                foreach ($this->namedTypes($actionParameter->getType()) as $type) {
                    if (! $type->isBuiltin() && is_a($type->getName(), Model::class, true)) {
                        return true; // implicit model binding
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function routeParameterNames(string $uri): array
    {
        preg_match_all('/\{(\w+)\??\}/', $uri, $matches);

        return $matches[1] ?? [];
    }

    /**
     * @return array<int, ReflectionNamedType>
     */
    private function namedTypes(mixed $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type];
        }

        if ($type instanceof ReflectionUnionType) {
            return array_values(array_filter(
                $type->getTypes(),
                static fn (mixed $inner): bool => $inner instanceof ReflectionNamedType,
            ));
        }

        return [];
    }

    private function resolveRoute(string $method, string $path, ?string $action): ?ResolvedRoute
    {
        return $this->routeResolver?->resolve(new ResolvableOperation(
            httpMethod: $method,
            path: $path,
            action: $action,
        ));
    }

    /**
     * @throws OpenApiDocsException
     */
    private function definitionFor(string $class, string $method, string $path): ErrorDefinition
    {
        if (isset($this->definitions[$class])) {
            return $this->definitions[$class];
        }

        $location = strtoupper($method) . ' ' . $path;

        if (! class_exists($class)) {
            throw new OpenApiDocsException(sprintf(
                'Error class %s declared for %s does not exist.',
                $class,
                $location,
            ));
        }

        if ($this->contract === null) {
            throw new OpenApiDocsException(sprintf(
                '%s declared for %s is not a documented error class: it must be a concrete subclass of errors.base_class inside a scanned directory (errors.paths).',
                $class,
                $location,
            ));
        }

        if (! $this->contract->isErrorClass($class)) {
            $baseClasses = $this->contract->baseClasses();

            throw new OpenApiDocsException(sprintf(
                '%s declared for %s is not an error class: %s',
                $class,
                $location,
                $baseClasses === []
                    ? 'no errors.base_class is configured.'
                    : 'it must be a concrete subclass of errors.base_class (' . implode(', ', $baseClasses) . ').',
            ));
        }

        throw new OpenApiDocsException(sprintf(
            'Error class %s declared for %s was never scanned; add its directory to the documentation set\'s errors.paths.',
            $class,
            $location,
        ));
    }

    /**
     * Status codes (as strings) the operation already documents.
     *
     * @return array<string, true>
     */
    private function documentedStatuses(OA\Operation $operation): array
    {
        if ($operation->responses === Generator::UNDEFINED || ! is_array($operation->responses)) {
            return [];
        }

        $statuses = [];

        foreach ($operation->responses as $response) {
            if ($response->response !== Generator::UNDEFINED) {
                $statuses[(string) $response->response] = true;
            }
        }

        return $statuses;
    }

    /**
     * One error for the status: a `$ref` to its reusable response. Several: an
     * inline response whose `error` property is a `oneOf` of their error bodies,
     * discriminated on `code` (see ErrorEnvelope::sharedStatusSchema()).
     *
     * Validation scenarios turn even a single-error response inline, because the
     * `$ref` points at a component every endpoint shares: the schema stays the
     * error's `{Name}Response` and the scenarios are listed in the description.
     *
     * @param  array<int, ErrorDefinition>  $definitions
     * @param  array<int, ValidationScenario>  $scenarios
     */
    private function buildResponse(int $status, array $definitions, array $scenarios = []): OA\Response
    {
        if (count($definitions) === 1 && $scenarios === []) {
            return new OA\Response([
                'response' => $status,
                'ref' => '#/components/responses/' . $definitions[0]->schemaName,
            ]);
        }

        $examples = [];

        if (count($definitions) === 1) {
            $description = $definitions[0]->codeAndMessage();
            $schema = new OA\Schema(['ref' => '#/components/schemas/' . $definitions[0]->responseSchemaName]);
        } else {
            $lines = [];

            foreach ($definitions as $definition) {
                $lines[] = '- ' . $definition->codeAndMessage();
            }

            $description = "Possible errors:\n\n" . implode("\n", $lines);
            $schema = $this->envelope->sharedStatusSchema($definitions);

            // A oneOf renders one synthesized body, which reads as if it were the only
            // answer. One named example per error, in the order listed above, gives the
            // reader every possible body. `summary` is the bare code, because that is
            // what a switcher lists; `description` repeats the error's own line, so a
            // renderer showing example metadata says which error is on screen.
            foreach ($definitions as $definition) {
                $examples[] = new OA\Examples([
                    'example' => $definition->code,
                    'summary' => $definition->code,
                    'description' => $definition->codeAndMessage(),
                    'value' => $this->envelope->exampleEnvelope($this->errorObjectExample($definition)),
                ]);
            }
        }

        if ($scenarios !== []) {
            $scenarioLines = array_map(
                static fn (ValidationScenario $scenario): string => '- ' . $scenario->describe(),
                $scenarios,
            );

            $description .= "\n\nPossible validation errors:\n\n" . implode("\n", $scenarioLines);
        }

        return new OA\Response([
            'response' => $status,
            'description' => $description,
            'content' => [
                new OA\MediaType(array_filter([
                    'mediaType' => 'application/json',
                    'schema' => $schema,
                    'examples' => $examples === [] ? null : $examples,
                ], static fn (mixed $value): bool => $value !== null)),
            ],
        ]);
    }
}
