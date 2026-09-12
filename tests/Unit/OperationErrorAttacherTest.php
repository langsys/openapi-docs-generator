<?php

use Illuminate\Routing\Route;
use Langsys\OpenApiDocsGenerator\Contracts\RouteResolver;
use Langsys\OpenApiDocsGenerator\Data\ResolvableOperation;
use Langsys\OpenApiDocsGenerator\Data\ResolvedRoute;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use Langsys\OpenApiDocsGenerator\Generators\OperationErrorAttacher;
use Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures\AttacherFixtureController;
use Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures\ClassThrowsController;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Generator;

const ATTACHER_CONTROLLER = AttacherFixtureController::class;
const API_KEY_MW = 'App\\Middleware\\ApiKeyAuth';

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
    $operation = operationFor(ATTACHER_CONTROLLER, 'single');
    $added = (new OperationErrorAttacher())->attach(docFor($operation), allErrorDefinitions());

    $responses = attachedResponses($operation);

    expect($added)->toBe(1)
        ->and(statusesOf($operation))->toBe(['200', '402'])
        ->and($responses['402'])->toBe(['$ref' => '#/components/responses/InsufficientBalanceError']);
});

it('uses oneOf with a code discriminator when several errors share a status', function () {
    $operation = operationFor(ATTACHER_CONTROLLER, 'sharedStatus');
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
    $operation = operationFor(ATTACHER_CONTROLLER, 'single', [
        new OA\Response(['response' => '200', 'description' => 'OK']),
        new OA\Response(['response' => '402', 'description' => 'Hand-written payment required']),
    ]);
    $added = (new OperationErrorAttacher())->attach(docFor($operation), allErrorDefinitions());

    expect($added)->toBe(0)
        ->and(attachedResponses($operation)['402']['description'])->toBe('Hand-written payment required');
});

it('adds errors implied by the route middleware', function () {
    $operation = operationFor(ATTACHER_CONTROLLER, 'bare');
    $attacher = new OperationErrorAttacher(
        routeResolver: attacherRouteResolver('api/things', [API_KEY_MW]),
        impliedErrors: ['middleware' => ['apikey' => [\Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError::class]]],
        aliasMap: ['apikey' => API_KEY_MW],
    );
    $attacher->attach(docFor($operation), allErrorDefinitions());

    expect(attachedResponses($operation)['401'])->toBe(['$ref' => '#/components/responses/UnauthenticatedError']);
});

it('does not imply errors when the route lacks the middleware', function () {
    $operation = operationFor(ATTACHER_CONTROLLER, 'bare');
    $attacher = new OperationErrorAttacher(
        routeResolver: attacherRouteResolver('api/things', ['App\\Middleware\\Other']),
        impliedErrors: ['middleware' => [API_KEY_MW => [\Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError::class]]],
    );

    expect($attacher->attach(docFor($operation), allErrorDefinitions()))->toBe(0)
        ->and(statusesOf($operation))->toBe(['200']);
});

it('deduplicates a class named by both #[Throws] and implied_errors', function () {
    $operation = operationFor(ATTACHER_CONTROLLER, 'alsoImplied');
    $attacher = new OperationErrorAttacher(
        routeResolver: attacherRouteResolver('api/things', [API_KEY_MW]),
        impliedErrors: ['middleware' => [API_KEY_MW => [\Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError::class]]],
    );
    $added = $attacher->attach(docFor($operation), allErrorDefinitions());

    expect($added)->toBe(1)
        ->and(attachedResponses($operation)['401'])->toBe(['$ref' => '#/components/responses/UnauthenticatedError']);
});

it('implies the validation error for an action taking a Data parameter', function () {
    $withData = operationFor(ATTACHER_CONTROLLER, 'store');
    $without = operationFor(ATTACHER_CONTROLLER, 'bare');
    $config = ['validation' => \Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\ValidationError::class];

    (new OperationErrorAttacher(impliedErrors: $config))->attach(docFor($withData), allErrorDefinitions());
    (new OperationErrorAttacher(impliedErrors: $config))->attach(docFor($without), allErrorDefinitions());

    expect(attachedResponses($withData)['422'])->toBe(['$ref' => '#/components/responses/ValidationError'])
        ->and(statusesOf($without))->toBe(['200']);
});

it('implies the not-found error only for a model-bound route parameter', function () {
    $config = ['not_found' => \Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError::class];

    $bound = operationFor(ATTACHER_CONTROLLER, 'show');
    (new OperationErrorAttacher(
        routeResolver: attacherRouteResolver('api/projects/{project}'),
        impliedErrors: $config,
    ))->attach(docFor($bound), allErrorDefinitions());

    $unbound = operationFor(ATTACHER_CONTROLLER, 'showSlug');
    (new OperationErrorAttacher(
        routeResolver: attacherRouteResolver('api/projects/{slug}'),
        impliedErrors: $config,
    ))->attach(docFor($unbound), allErrorDefinitions());

    $noParams = operationFor(ATTACHER_CONTROLLER, 'show');
    (new OperationErrorAttacher(
        routeResolver: attacherRouteResolver('api/projects'),
        impliedErrors: $config,
    ))->attach(docFor($noParams), allErrorDefinitions());

    expect(statusesOf($bound))->toBe(['200', '401'])
        ->and(statusesOf($unbound))->toBe(['200'])
        ->and(statusesOf($noParams))->toBe(['200']);
});

it('implies the not-found error for any route parameter when not_found_binding is any', function () {
    $operation = operationFor(ATTACHER_CONTROLLER, 'showSlug');
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
    $operation = operationFor(ATTACHER_CONTROLLER, 'show');
    $added = (new OperationErrorAttacher(impliedErrors: [
        'middleware' => [API_KEY_MW => [\Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError::class]],
        'not_found' => \Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError::class,
    ]))->attach(docFor($operation), allErrorDefinitions());

    expect($added)->toBe(0);
});

it('leaves operations without a controller context alone', function () {
    $operation = new OA\Get(['responses' => [new OA\Response(['response' => '200', 'description' => 'OK'])]]);

    expect((new OperationErrorAttacher())->attach(docFor($operation), allErrorDefinitions()))->toBe(0);
});

it('fails with a clear message when #[Throws] names a class without #[ErrorCode]', function () {
    $operation = operationFor(ATTACHER_CONTROLLER, 'notAnError');

    (new OperationErrorAttacher())->attach(docFor($operation), allErrorDefinitions());
})->throws(OpenApiDocsException::class, 'carries no #[ErrorCode]');

it('fails when a declared error carries #[ErrorCode] but was never scanned', function () {
    $operation = operationFor(ATTACHER_CONTROLLER, 'single');

    (new OperationErrorAttacher())->attach(docFor($operation), []);
})->throws(OpenApiDocsException::class, 'was never scanned; add its directory');

it('does nothing when the document has no paths', function () {
    $openapi = new OA\OpenApi(['info' => new OA\Info(['title' => 'T', 'version' => '1.0'])]);

    expect((new OperationErrorAttacher())->attach($openapi, allErrorDefinitions()))->toBe(0)
        ->and($openapi->paths)->toBe(Generator::UNDEFINED);
});
