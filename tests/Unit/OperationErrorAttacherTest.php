<?php

use Illuminate\Routing\Route;
use Langsys\OpenApiDocsGenerator\Contracts\ImpliedErrorRule;
use Langsys\OpenApiDocsGenerator\Contracts\RouteResolver;
use Langsys\OpenApiDocsGenerator\Data\OperationContext;
use Langsys\OpenApiDocsGenerator\Data\ResolvableOperation;
use Langsys\OpenApiDocsGenerator\Data\ResolvedRoute;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use Langsys\OpenApiDocsGenerator\Generators\OperationErrorAttacher;
use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError as ForbiddenStandIn;
use Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures\AttacherFixtureController;
use Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures\NoopRule;
use Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures\SourceScanRule;
use Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures\ClassThrowsController;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Generator;

// Helpers, not constants: this file uses describe(), which re-evaluates the
// top-level scope and would warn on a redefined const.
function attacherController(): string
{
    return AttacherFixtureController::class;
}

function apiKeyMiddleware(): string
{
    return 'App\\Middleware\\ApiKeyAuth';
}

/** Every error definition from both error fixture directories. */
function allErrorDefinitions(): array
{
    $builder = new DtoSchemaBuilder(
        [dirname(__DIR__) . '/ErrorFixtures', dirname(__DIR__) . '/ErrorOperationFixtures'],
        new ExampleGenerator([], []),
        [],
    );
    $builder->buildAll();

    return $builder->getErrorDefinitions();
}

/** An operation whose parse context points at a fixture controller action. */
function operationFor(string $class, string $method, array $responses = []): OA\Get
{
    $parts = explode('\\', $class);
    $shortName = array_pop($parts);

    $operation = new OA\Get([
        'responses' => $responses === [] ? [new OA\Response(['response' => '200', 'description' => 'OK'])] : $responses,
    ]);
    $operation->_context = new Context([
        'namespace' => implode('\\', $parts),
        'class' => $shortName,
        'method' => $method,
    ]);

    return $operation;
}

function docFor(OA\Get $operation, string $path = '/api/things'): OA\OpenApi
{
    $pathItem = new OA\PathItem(['path' => $path]);
    $pathItem->get = $operation;

    $openapi = new OA\OpenApi(['info' => new OA\Info(['title' => 'T', 'version' => '1.0'])]);
    $openapi->paths = [$pathItem];

    return $openapi;
}

function attacherRouteResolver(string $uri, array $middleware = []): RouteResolver
{
    return new class($uri, $middleware) implements RouteResolver {
        public function __construct(private string $uri, private array $middleware) {}

        public function resolve(ResolvableOperation $operation): ?ResolvedRoute
        {
            return new ResolvedRoute(
                new Route(['GET'], $this->uri, fn () => null),
                $this->middleware,
                $operation->action,
            );
        }
    };
}

/** @return array<string, array> status => decoded response */
function attachedResponses(OA\Get $operation): array
{
    $out = [];
    foreach ($operation->responses as $response) {
        $out[(string) $response->response] = json_decode(json_encode($response), true);
    }

    return $out;
}

/** @return array<int, string> documented statuses, in document order */
function statusesOf(OA\Get $operation): array
{
    return array_map('strval', array_keys(attachedResponses($operation)));
}

it('attaches a $ref to the reusable response when one error owns the status', function () {
    $operation = operationFor(attacherController(), 'single');
    $added = (new OperationErrorAttacher())->attach(docFor($operation), allErrorDefinitions());

    $responses = attachedResponses($operation);

    expect($added)->toBe(1)
        ->and(statusesOf($operation))->toBe(['200', '402'])
        ->and($responses['402'])->toBe(['$ref' => '#/components/responses/InsufficientBalanceError']);
});

it('uses oneOf with a code discriminator when several errors share a status', function () {
    $operation = operationFor(attacherController(), 'sharedStatus');
    (new OperationErrorAttacher())->attach(docFor($operation), allErrorDefinitions());

    $schema = attachedResponses($operation)['422']['content']['application/json']['schema'];

    expect($schema['oneOf'])->toBe([
        ['$ref' => '#/components/schemas/BatchTooLargeErrorResponse'],
        ['$ref' => '#/components/schemas/ValidationErrorResponse'],
    ])
        ->and($schema['discriminator'])->toBe([
            'propertyName' => 'code',
            'mapping' => [
                'batch_too_large' => '#/components/schemas/BatchTooLargeErrorResponse',
                'validation_failed' => '#/components/schemas/ValidationErrorResponse',
            ],
        ])
        ->and(attachedResponses($operation)['422']['description'])
        ->toContain('- `batch_too_large`: The submitted batch has more items than the endpoint allows.');
});

it('leaves a hand-written response for the same status untouched', function () {
    $operation = operationFor(attacherController(), 'single', [
        new OA\Response(['response' => '200', 'description' => 'OK']),
        new OA\Response(['response' => '402', 'description' => 'Hand-written payment required']),
    ]);
    $added = (new OperationErrorAttacher())->attach(docFor($operation), allErrorDefinitions());

    expect($added)->toBe(0)
        ->and(attachedResponses($operation)['402']['description'])->toBe('Hand-written payment required');
});

it('adds errors implied by the route middleware', function () {
    $operation = operationFor(attacherController(), 'bare');
    $attacher = new OperationErrorAttacher(
        routeResolver: attacherRouteResolver('api/things', [apiKeyMiddleware()]),
        impliedErrors: ['middleware' => ['apikey' => [\Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError::class]]],
        aliasMap: ['apikey' => apiKeyMiddleware()],
    );
    $attacher->attach(docFor($operation), allErrorDefinitions());

    expect(attachedResponses($operation)['401'])->toBe(['$ref' => '#/components/responses/UnauthenticatedError']);
});

it('does not imply errors when the route lacks the middleware', function () {
    $operation = operationFor(attacherController(), 'bare');
    $attacher = new OperationErrorAttacher(
        routeResolver: attacherRouteResolver('api/things', ['App\\Middleware\\Other']),
        impliedErrors: ['middleware' => [apiKeyMiddleware() => [\Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError::class]]],
    );

    expect($attacher->attach(docFor($operation), allErrorDefinitions()))->toBe(0)
        ->and(statusesOf($operation))->toBe(['200']);
});

it('deduplicates a class named by both #[Throws] and implied_errors', function () {
    $operation = operationFor(attacherController(), 'alsoImplied');
    $attacher = new OperationErrorAttacher(
        routeResolver: attacherRouteResolver('api/things', [apiKeyMiddleware()]),
        impliedErrors: ['middleware' => [apiKeyMiddleware() => [\Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError::class]]],
    );
    $added = $attacher->attach(docFor($operation), allErrorDefinitions());

    expect($added)->toBe(1)
        ->and(attachedResponses($operation)['401'])->toBe(['$ref' => '#/components/responses/UnauthenticatedError']);
});

it('implies the validation error for an action taking a Data parameter', function () {
    $withData = operationFor(attacherController(), 'store');
    $without = operationFor(attacherController(), 'bare');
    $config = ['validation' => \Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\ValidationError::class];

    (new OperationErrorAttacher(impliedErrors: $config))->attach(docFor($withData), allErrorDefinitions());
    (new OperationErrorAttacher(impliedErrors: $config))->attach(docFor($without), allErrorDefinitions());

    expect(attachedResponses($withData)['422'])->toBe(['$ref' => '#/components/responses/ValidationError'])
        ->and(statusesOf($without))->toBe(['200']);
});

it('implies the not-found error only for a model-bound route parameter', function () {
    $config = ['not_found' => \Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError::class];

    $bound = operationFor(attacherController(), 'show');
    (new OperationErrorAttacher(
        routeResolver: attacherRouteResolver('api/projects/{project}'),
        impliedErrors: $config,
    ))->attach(docFor($bound), allErrorDefinitions());

    $unbound = operationFor(attacherController(), 'showSlug');
    (new OperationErrorAttacher(
        routeResolver: attacherRouteResolver('api/projects/{slug}'),
        impliedErrors: $config,
    ))->attach(docFor($unbound), allErrorDefinitions());

    $noParams = operationFor(attacherController(), 'show');
    (new OperationErrorAttacher(
        routeResolver: attacherRouteResolver('api/projects'),
        impliedErrors: $config,
    ))->attach(docFor($noParams), allErrorDefinitions());

    expect(statusesOf($bound))->toBe(['200', '401'])
        ->and(statusesOf($unbound))->toBe(['200'])
        ->and(statusesOf($noParams))->toBe(['200']);
});

it('implies the not-found error for any route parameter when not_found_binding is any', function () {
    $operation = operationFor(attacherController(), 'showSlug');
    (new OperationErrorAttacher(
        routeResolver: attacherRouteResolver('api/projects/{slug}'),
        impliedErrors: [
            'not_found' => \Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError::class,
            'not_found_binding' => 'any',
        ],
    ))->attach(docFor($operation), allErrorDefinitions());

    expect(statusesOf($operation))->toBe(['200', '401']);
});

it('applies a class-level #[Throws] to every action of the controller', function () {
    $operation = operationFor(ClassThrowsController::class, 'index');
    (new OperationErrorAttacher())->attach(docFor($operation), allErrorDefinitions());

    expect(attachedResponses($operation)['401'])->toBe(['$ref' => '#/components/responses/UnauthenticatedError']);
});

it('applies no middleware or not-found rules without a route resolver', function () {
    $operation = operationFor(attacherController(), 'show');
    $added = (new OperationErrorAttacher(impliedErrors: [
        'middleware' => [apiKeyMiddleware() => [\Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError::class]],
        'not_found' => \Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError::class,
    ]))->attach(docFor($operation), allErrorDefinitions());

    expect($added)->toBe(0);
});

it('leaves operations without a controller context alone', function () {
    $operation = new OA\Get(['responses' => [new OA\Response(['response' => '200', 'description' => 'OK'])]]);

    expect((new OperationErrorAttacher())->attach(docFor($operation), allErrorDefinitions()))->toBe(0);
});

it('fails with a clear message when #[Throws] names a class without #[ErrorCode]', function () {
    $operation = operationFor(attacherController(), 'notAnError');

    (new OperationErrorAttacher())->attach(docFor($operation), allErrorDefinitions());
})->throws(OpenApiDocsException::class, 'carries no #[ErrorCode]');

it('fails when a declared error carries #[ErrorCode] but was never scanned', function () {
    $operation = operationFor(attacherController(), 'single');

    (new OperationErrorAttacher())->attach(docFor($operation), []);
})->throws(OpenApiDocsException::class, 'was never scanned; add its directory');

it('does nothing when the document has no paths', function () {
    $openapi = new OA\OpenApi(['info' => new OA\Info(['title' => 'T', 'version' => '1.0'])]);

    expect((new OperationErrorAttacher())->attach($openapi, allErrorDefinitions()))->toBe(0)
        ->and($openapi->paths)->toBe(Generator::UNDEFINED);
});


describe('custom implied error rules', function () {
    it('runs a rule built from a class-and-args descriptor', function () {
        $guarded = operationFor(attacherController(), 'guarded');
        $plain = operationFor(attacherController(), 'bare');
        $config = ['rules' => [
            ['class' => SourceScanRule::class, 'args' => [['FakeGuard::authorize' => [ForbiddenStandIn::class]]]],
        ]];

        (new OperationErrorAttacher(impliedErrors: $config))->attach(docFor($guarded), allErrorDefinitions());
        (new OperationErrorAttacher(impliedErrors: $config))->attach(docFor($plain), allErrorDefinitions());

        expect(attachedResponses($guarded)['401'])->toBe(['$ref' => '#/components/responses/UnauthenticatedError'])
            ->and(statusesOf($plain))->toBe(['200']);
    });

    it('accepts a rule given as an instance or a bare class name', function () {
        $fromInstance = operationFor(attacherController(), 'guarded');
        (new OperationErrorAttacher(impliedErrors: [
            'rules' => [new SourceScanRule(['FakeGuard::authorize' => [ForbiddenStandIn::class]])],
        ]))->attach(docFor($fromInstance), allErrorDefinitions());

        $fromName = operationFor(attacherController(), 'guarded');
        $added = (new OperationErrorAttacher(impliedErrors: [
            'rules' => [NoopRule::class],
        ]))->attach(docFor($fromName), allErrorDefinitions());

        expect(statusesOf($fromInstance))->toBe(['200', '401'])
            ->and($added)->toBe(0);
    });

    it('gives a rule the resolved route and the reflected action', function () {
        $recorder = new class implements ImpliedErrorRule {
            public array $seen = [];

            public function errorsFor(OperationContext $context, ?ReflectionMethod $action): array
            {
                $this->seen = [
                    'path' => $context->path,
                    'method' => $context->httpMethod,
                    'uri' => $context->route?->uri(),
                    'action' => $action?->getName(),
                ];

                return [];
            }
        };

        (new OperationErrorAttacher(
            routeResolver: attacherRouteResolver('api/projects/{project}'),
            impliedErrors: ['rules' => [$recorder]],
        ))->attach(docFor(operationFor(attacherController(), 'show'), '/api/projects/{project}'), allErrorDefinitions());

        expect($recorder->seen)->toBe([
            'path' => '/api/projects/{project}',
            'method' => 'get',
            'uri' => 'api/projects/{project}',
            'action' => 'show',
        ]);
    });

    it('deduplicates a rule-supplied class against #[Throws]', function () {
        $operation = operationFor(attacherController(), 'alsoImplied');
        $added = (new OperationErrorAttacher(impliedErrors: [
            'rules' => [new SourceScanRule(['public function' => [ForbiddenStandIn::class]])],
        ]))->attach(docFor($operation), allErrorDefinitions());

        expect($added)->toBe(1)
            ->and(attachedResponses($operation)['401'])->toBe(['$ref' => '#/components/responses/UnauthenticatedError']);
    });

    it('rejects a rule class that does not exist', function () {
        new OperationErrorAttacher(impliedErrors: ['rules' => ['App\\Nope\\MissingRule']]);
    })->throws(OpenApiDocsException::class, 'Implied error rule class does not exist');

    it('rejects a rule class that does not implement the contract', function () {
        new OperationErrorAttacher(impliedErrors: ['rules' => [attacherController()]]);
    })->throws(OpenApiDocsException::class, 'must implement');

    it('rejects an unrecognized rule descriptor', function () {
        new OperationErrorAttacher(impliedErrors: ['rules' => [['nonsense' => true]]]);
    })->throws(OpenApiDocsException::class, 'Unrecognized implied error rule descriptor');
});
