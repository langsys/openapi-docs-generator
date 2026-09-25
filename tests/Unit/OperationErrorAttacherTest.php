<?php

use Illuminate\Routing\Route;
use Langsys\OpenApiDocsGenerator\Contracts\ImpliedErrorRule;
use Langsys\OpenApiDocsGenerator\Contracts\RouteResolver;
use Langsys\OpenApiDocsGenerator\Data\OperationContext;
use Langsys\OpenApiDocsGenerator\Data\ResolvableOperation;
use Langsys\OpenApiDocsGenerator\Data\ValidationScenario;
use Langsys\OpenApiDocsGenerator\Data\ResolvedRoute;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use Langsys\OpenApiDocsGenerator\Generators\DtoSchemaBuilder;
use Langsys\OpenApiDocsGenerator\Generators\ErrorContract;
use Langsys\OpenApiDocsGenerator\Generators\ExampleGenerator;
use Langsys\OpenApiDocsGenerator\Generators\OperationErrorAttacher;
use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\ApiError;
use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\UnauthenticatedError as ForbiddenStandIn;
use Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures\AttacherFixtureController;
use Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures\FixedScenarioResolver;
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
        ['base_class' => ApiError::class],
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

/** A document whose components carry the error schemas, as the real pipeline has them. */
function docWithErrorSchemas(OA\Get $operation, string $path = '/api/things'): OA\OpenApi
{
    $openapi = docFor($operation, $path);
    $builder = new DtoSchemaBuilder(
        [dirname(__DIR__) . '/ErrorFixtures', dirname(__DIR__) . '/ErrorOperationFixtures'],
        new ExampleGenerator([], []),
        [],
        ['base_class' => ApiError::class],
    );
    $openapi->components = new OA\Components(['schemas' => $builder->buildAll()]);

    return $openapi;
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

it('puts a code-discriminated oneOf on the error property when several errors share a status', function () {
    $operation = operationFor(attacherController(), 'sharedStatus');
    (new OperationErrorAttacher())->attach(docFor($operation), allErrorDefinitions());

    $response = attachedResponses($operation)['422'];
    $schema = $response['content']['application/json']['schema'];

    // One envelope: OpenAPI 3.0 can't discriminate on the nested error.code from the response level.
    expect(array_keys($schema['properties']))->toBe(['status', 'data', 'error'])
        ->and($schema['required'])->toBe(['status', 'error'])
        ->and($schema)->not->toHaveKey('oneOf');

    $error = $schema['properties']['error'];

    expect($error['oneOf'])->toBe([
        ['$ref' => '#/components/schemas/BatchTooLargeErrorBody'],
        ['$ref' => '#/components/schemas/ValidationErrorBody'],
    ])
        ->and($error['discriminator'])->toBe([
            'propertyName' => 'code',
            'mapping' => [
                'batch_too_large' => '#/components/schemas/BatchTooLargeErrorBody',
                'validation_failed' => '#/components/schemas/ValidationErrorBody',
            ],
        ])
        ->and($response['description'])
        ->toContain('- `batch_too_large`: The submitted batch has more items than the endpoint allows.');
});

it('builds the shared-status response from the envelope the schemas were built with', function () {
    $builder = new DtoSchemaBuilder(
        [dirname(__DIR__) . '/ErrorFixtures', dirname(__DIR__) . '/ErrorOperationFixtures'],
        new ExampleGenerator([], []),
        [],
        ['base_class' => ApiError::class, 'response_fields' => ['error' => 'failure'], 'error_fields' => ['code' => 'reason']],
    );
    $builder->buildAll();

    $operation = operationFor(attacherController(), 'sharedStatus');
    (new OperationErrorAttacher())->attach(docFor($operation), $builder->getErrorDefinitions(), $builder->getErrorEnvelope());

    $properties = attachedResponses($operation)['422']['content']['application/json']['schema']['properties'];

    expect($properties)->toHaveKey('failure')
        ->and($properties)->not->toHaveKey('error')
        ->and($properties['failure']['discriminator']['propertyName'])->toBe('reason');
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

it('fails with a clear message when #[Throws] names a class that is not an error class', function () {
    $operation = operationFor(attacherController(), 'notAnError');

    (new OperationErrorAttacher())->attach(docFor($operation), allErrorDefinitions(), null, new ErrorContract(ApiError::class));
})->throws(OpenApiDocsException::class, 'is not an error class: it must be a concrete subclass of errors.base_class');

it('fails when a declared error class was never scanned', function () {
    $operation = operationFor(attacherController(), 'single');

    (new OperationErrorAttacher())->attach(docFor($operation), [], null, new ErrorContract(ApiError::class));
})->throws(OpenApiDocsException::class, 'was never scanned; add its directory');

it('fails with a message covering both causes when no contract is given', function () {
    $operation = operationFor(attacherController(), 'notAnError');

    (new OperationErrorAttacher())->attach(docFor($operation), allErrorDefinitions());
})->throws(OpenApiDocsException::class, 'is not a documented error class');

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

describe('an @OA annotation on a helper method', function () {
    /** A resolver whose matched route names $routeAction as its controller action. */
    function helperRouteResolver(?string $routeAction): RouteResolver
    {
        return new class($routeAction) implements RouteResolver {
            public function __construct(private ?string $routeAction) {}

            public function resolve(ResolvableOperation $operation): ?ResolvedRoute
            {
                return new ResolvedRoute(new Route(['POST'], 'api/projects', fn () => null), [], $this->routeAction);
            }
        };
    }

    function actionRecorder(): ImpliedErrorRule
    {
        return new class implements ImpliedErrorRule {
            public ?string $seen = null;

            public function errorsFor(OperationContext $context, ?ReflectionMethod $action): array
            {
                $this->seen = $action?->getName();

                return [];
            }
        };
    }

    it('inspects the matched route\'s real action for rules and structural checks', function () {
        $operation = operationFor(attacherController(), 'annotatedHelper');
        $recorder = actionRecorder();

        (new OperationErrorAttacher(
            routeResolver: helperRouteResolver(AttacherFixtureController::class . '@store'),
            impliedErrors: [
                'validation' => \Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\ValidationError::class,
                'rules' => [$recorder],
            ],
        ))->attach(docFor($operation), allErrorDefinitions());

        // 402 from #[Throws] on the annotated helper; 422 because store() takes a Data parameter.
        expect(statusesOf($operation))->toBe(['200', '402', '422'])
            ->and($recorder->seen)->toBe('store');
    });

    it('falls back to the annotated method when the route has no controller action', function () {
        $operation = operationFor(attacherController(), 'annotatedHelper');
        $recorder = actionRecorder();

        (new OperationErrorAttacher(
            routeResolver: helperRouteResolver(null),
            impliedErrors: [
                'validation' => \Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\ValidationError::class,
                'rules' => [$recorder],
            ],
        ))->attach(docFor($operation), allErrorDefinitions());

        // The helper takes no Data parameter, so no 422.
        expect(statusesOf($operation))->toBe(['200', '402'])
            ->and($recorder->seen)->toBe('annotatedHelper');
    });
});

describe('validation scenarios', function () {
    function validationClass(): string
    {
        return \Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\ValidationError::class;
    }

    /** An operation whose action takes a Data parameter, so validation is implied. */
    function scenarioOperation(): OA\Get
    {
        return operationFor(attacherController(), 'store');
    }

    it('lists the scenarios on an inline validation response instead of the shared $ref', function () {
        $operation = scenarioOperation();

        (new OperationErrorAttacher(impliedErrors: [
            'validation' => validationClass(),
            'validation_scenarios' => FixedScenarioResolver::class,
        ]))->attach(docFor($operation), allErrorDefinitions());

        $response = attachedResponses($operation)['422'];

        expect($response)->not->toHaveKey('$ref')
            ->and($response['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/ValidationErrorResponse')
            ->and($response['description'])->toBe(
                "`validation_failed`: One or more request fields failed validation.\n\n"
                . "Possible validation errors:\n\n"
                . "- `credit_card.cc_number`.`already_taken`: This credit card has already been added.\n"
                . "- `locale`.`invalid_option`: The locale is not valid.\n"
                . "- `locale`.`invalid_option`: The locale is not a target locale of this project.\n"
                . '- `expired`: This invitation has expired.'
            );
    });

    it('leaves the response as a $ref when the resolver returns nothing', function () {
        $operation = scenarioOperation();
        $empty = new class implements \Langsys\OpenApiDocsGenerator\Contracts\ValidationScenarioResolver {
            public function scenariosFor(OperationContext $context, ?ReflectionMethod $action): array
            {
                return [];
            }
        };

        (new OperationErrorAttacher(impliedErrors: [
            'validation' => validationClass(),
            'validation_scenarios' => [$empty],
        ]))->attach(docFor($operation), allErrorDefinitions());

        expect(attachedResponses($operation)['422'])->toBe(['$ref' => '#/components/responses/ValidationError']);
    });

    it('drops exact duplicates but keeps one code carrying different messages', function () {
        $operation = scenarioOperation();
        $resolver = new FixedScenarioResolver(
            new ValidationScenario('locale', 'invalid_option', 'The locale is not valid.'),
            new ValidationScenario('locale', 'invalid_option', 'The locale is not valid.'),
            new ValidationScenario('locale', 'invalid_option', 'The locale is not a target locale of this project.'),
            new ValidationScenario(null, 'not_allowed', 'Wire transfer is only available for Enterprise plans.'),
            new ValidationScenario(null, 'not_allowed', 'This plan has no fixed price. Please use wire transfer.'),
        );

        (new OperationErrorAttacher(impliedErrors: [
            'validation' => validationClass(),
            'validation_scenarios' => $resolver,
        ]))->attach(docFor($operation), allErrorDefinitions());

        $lines = array_values(array_filter(
            explode("\n", attachedResponses($operation)['422']['description']),
            static fn (string $line): bool => str_starts_with($line, '- '),
        ));

        // The repeat is gone; the same code with a different message survives, because
        // several rules legitimately share a code.
        expect($lines)->toBe([
            '- `locale`.`invalid_option`: The locale is not valid.',
            '- `locale`.`invalid_option`: The locale is not a target locale of this project.',
            '- `not_allowed`: Wire transfer is only available for Enterprise plans.',
            '- `not_allowed`: This plan has no fixed price. Please use wire transfer.',
        ]);
    });

    it('keeps the oneOf and adds the scenarios when the status is shared', function () {
        $operation = operationFor(attacherController(), 'sharedStatus');

        (new OperationErrorAttacher(impliedErrors: [
            'validation' => validationClass(),
            'validation_scenarios' => FixedScenarioResolver::class,
        ]))->attach(docFor($operation), allErrorDefinitions());

        $response = attachedResponses($operation)['422'];

        expect($response['content']['application/json']['schema']['properties']['error'])->toHaveKey('oneOf')
            ->and($response['description'])->toContain('Possible errors:')
            ->and($response['description'])->toContain('- `batch_too_large`:')
            ->and($response['description'])->toContain("Possible validation errors:\n\n- `credit_card.cc_number`.`already_taken`:");
    });

    it('leaves a hand-written validation response untouched', function () {
        $operation = operationFor(attacherController(), 'store', [
            new OA\Response(['response' => '200', 'description' => 'OK']),
            new OA\Response(['response' => '422', 'description' => 'Hand-written validation']),
        ]);

        (new OperationErrorAttacher(impliedErrors: [
            'validation' => validationClass(),
            'validation_scenarios' => FixedScenarioResolver::class,
        ]))->attach(docFor($operation), allErrorDefinitions());

        expect(attachedResponses($operation)['422']['description'])->toBe('Hand-written validation');
    });

    it('fails when scenarios are returned but no validation error class is configured', function () {
        $operation = scenarioOperation();

        (new OperationErrorAttacher(impliedErrors: [
            'validation_scenarios' => FixedScenarioResolver::class,
        ]))->attach(docFor($operation), allErrorDefinitions());
    })->throws(OpenApiDocsException::class, 'no implied_errors.validation error class is configured');

    it('rejects a resolver that returns something other than scenarios', function () {
        $operation = scenarioOperation();
        $wrong = new class implements \Langsys\OpenApiDocsGenerator\Contracts\ValidationScenarioResolver {
            public function scenariosFor(OperationContext $context, ?ReflectionMethod $action): array
            {
                return [['field' => 'cc_number', 'code' => 'already_taken', 'message' => 'Nope']];
            }
        };

        (new OperationErrorAttacher(impliedErrors: [
            'validation' => validationClass(),
            'validation_scenarios' => [$wrong],
        ]))->attach(docFor($operation), allErrorDefinitions());
    })->throws(OpenApiDocsException::class, 'must return Langsys\OpenApiDocsGenerator\Data\ValidationScenario instances; got array');

    it('accepts a resolver as a class name, a class-and-args descriptor, or an instance', function () {
        $configs = [
            FixedScenarioResolver::class,
            ['class' => FixedScenarioResolver::class, 'args' => [new ValidationScenario('email', 'taken', 'That email is taken.')]],
            new FixedScenarioResolver(new ValidationScenario('email', 'taken', 'That email is taken.')),
        ];

        foreach ($configs as $config) {
            $operation = scenarioOperation();

            (new OperationErrorAttacher(impliedErrors: [
                'validation' => validationClass(),
                'validation_scenarios' => $config,
            ]))->attach(docFor($operation), allErrorDefinitions());

            expect(attachedResponses($operation)['422']['description'])->toContain('Possible validation errors:');
        }
    });

    it('rejects a resolver class that does not exist or does not implement the contract', function () {
        expect(fn () => new OperationErrorAttacher(impliedErrors: ['validation_scenarios' => 'App\\Nope\\Resolver']))
            ->toThrow(OpenApiDocsException::class, 'Validation scenario resolver class does not exist');

        expect(fn () => new OperationErrorAttacher(impliedErrors: ['validation_scenarios' => attacherController()]))
            ->toThrow(OpenApiDocsException::class, 'must implement');

        expect(fn () => new OperationErrorAttacher(impliedErrors: ['validation_scenarios' => [['nonsense' => true]]]))
            ->toThrow(OpenApiDocsException::class, 'Unrecognized validation scenario resolver descriptor');
    });

    it('rejects an empty code, message or field on a scenario', function () {
        expect(fn () => new ValidationScenario('f', '', 'm'))->toThrow(OpenApiDocsException::class, 'non-empty code')
            ->and(fn () => new ValidationScenario('f', 'c', ''))->toThrow(OpenApiDocsException::class, 'non-empty message')
            ->and(fn () => new ValidationScenario('', 'c', 'm'))->toThrow(OpenApiDocsException::class, 'pass null when the rule validates the whole payload');
    });
});

describe('shared-status examples', function () {
    it('gives a shared status one named example per error, in the listed order', function () {
        $operation = operationFor(attacherController(), 'sharedStatus');

        (new OperationErrorAttacher())->attach(docWithErrorSchemas($operation), allErrorDefinitions());

        $examples = attachedResponses($operation)['422']['content']['application/json']['examples'];

        // Keyed by code, ordered as the "Possible errors" lines above them.
        expect(array_keys($examples))->toBe(['batch_too_large', 'validation_failed'])
            ->and($examples['batch_too_large']['summary'])->toBe('batch_too_large')
            ->and($examples['validation_failed']['summary'])->toBe('validation_failed');

        // Each example says which error it is, in the notation the description list uses.
        expect($examples['batch_too_large']['description'])
            ->toBe('`batch_too_large`: The submitted batch has more items than the endpoint allows.')
            ->and($examples['validation_failed']['description'])
            ->toBe('`validation_failed`: One or more request fields failed validation.');

        $value = $examples['batch_too_large']['value'];

        // A whole response body, not just the error object.
        expect(array_keys($value))->toBe(['status', 'data', 'error'])
            ->and($value['status'])->toBeFalse()
            ->and($value['data'])->toBe([]);

        // Built from what the Body schema already advertises, including typed details.
        expect($value['error']['code'])->toBe('batch_too_large')
            ->and($value['error']['message'])->toBe('The submitted batch has more items than the endpoint allows.')
            ->and($value['error']['template'])->toBe('The submitted batch has more items than the endpoint allows.')
            ->and(array_keys($value['error']['details']))->toBe(['max', 'submitted']);
    });

    it('leaves an app-owned envelope field out of the example rather than inventing it', function () {
        $operation = operationFor(attacherController(), 'sharedStatus');

        (new OperationErrorAttacher())->attach(docWithErrorSchemas($operation), allErrorDefinitions());

        $error = attachedResponses($operation)['422']['content']['application/json']['examples']['validation_failed']['value']['error'];

        // ValidationError's `errors` map is an #[EnvelopeField] with no example of its own.
        expect(array_keys($error))->toBe(['message', 'code', 'template'])
            ->and($error)->not->toHaveKey('errors');
    });

    it('falls back to what the definition knows when the Body schema is absent', function () {
        $operation = operationFor(attacherController(), 'sharedStatus');

        (new OperationErrorAttacher())->attach(docFor($operation), allErrorDefinitions());

        $error = attachedResponses($operation)['422']['content']['application/json']['examples']['batch_too_large']['value']['error'];

        expect($error)->toBe([
            'message' => 'The submitted batch has more items than the endpoint allows.',
            'code' => 'batch_too_large',
            'template' => 'The submitted batch has more items than the endpoint allows.',
        ]);
    });

    it('gives an array detail a one-item list from its items example', function () {
        $operation = operationFor(attacherController(), 'arrayDetails');

        (new OperationErrorAttacher())->attach(docWithErrorSchemas($operation), allErrorDefinitions());

        $examples = attachedResponses($operation)['422']['content']['application/json']['examples'];

        expect(array_keys($examples))->toBe(['account_owns_organizations', 'validation_failed'])
            ->and($examples['account_owns_organizations']['value']['error']['details'])
            ->toBe(['owned_organization_ids' => ['5b0e9c1f']]);
    });

    it('adds no examples to a single-error response', function () {
        $operation = operationFor(attacherController(), 'single');

        (new OperationErrorAttacher())->attach(docWithErrorSchemas($operation), allErrorDefinitions());

        expect(attachedResponses($operation)['402'])->toBe(['$ref' => '#/components/responses/InsufficientBalanceError']);
    });

    it('labels the error wrapper as one of the errors listed above', function () {
        $operation = operationFor(attacherController(), 'sharedStatus');

        (new OperationErrorAttacher())->attach(docWithErrorSchemas($operation), allErrorDefinitions());

        $error = attachedResponses($operation)['422']['content']['application/json']['schema']['properties']['error'];

        expect($error['description'])->toBe('One of the errors listed above; `code` says which');
    });
});
