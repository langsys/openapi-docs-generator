<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Documentation
    |--------------------------------------------------------------------------
    */
    'default' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Documentation Sets
    |--------------------------------------------------------------------------
    | Each key defines a documentation set that can override any value from
    | the 'defaults' section. Useful for API versioning (v1, v2) or
    | separating public/internal APIs.
    */
    'documentations' => [
        'default' => [
            'paths' => [
                'docs_json' => 'api-docs.json',
                'docs_yaml' => 'api-docs.yaml',
                'annotations' => [app_path()],
            ],
        ],

        /*
        | --- Filtered documentation sets ---------------------------------------
        |
        | A documentation set can emit only a subset of your API — e.g. an
        | "integration" spec containing just the endpoints an API key can call.
        | Selection is by construction: only the chosen operations, and the
        | schemas/parameters/tags they transitively reference, are emitted.
        |
        | Operations are matched to their Laravel route (by controller action,
        | falling back to the path signature) and selected by a pluggable filter.
        | The default discriminator is route middleware — ground truth, unlike
        | hand-written `security` annotations which can drift.
        |
        | 'integration' => [
        |     'paths'  => ['docs_json' => 'api-docs-integration.json'],
        |     'filter' => [
        |         // Keep operations matching ANY include descriptor...
        |         'include' => [
        |             ['middleware' => 'auth.apikey'],   // MiddlewareFilter (alias or FQCN)
        |             // ['tag' => 'Public'],
        |             // ['path' => 'webhooks/*'],
        |             // ['operationId' => 'listProjects'],
        |             // ['class' => \App\Docs\CustomFilter::class, 'args' => []],
        |         ],
        |         // ...and NONE of the exclude descriptors.
        |         // 'exclude' => [ ['middleware' => 'auth.internal'] ],
        |
        |         // Operations that can't be matched to a route: 'exclude' (default) or 'include'.
        |         'unmatched' => 'exclude',
        |
        |         // Optional: prepend a base prefix (e.g. 'api') to OpenAPI paths
        |         // before path-signature matching. Usually unnecessary.
        |         // 'route_prefix' => null,
        |     ],
        |
        |     // Force the displayed security on every surviving operation so the
        |     // set stays auth-consistent with the filter, and restrict advertised
        |     // security schemes to those it names.
        |     'security_override' => [ ['apiKey' => []] ],
        |
        |     // Give this set its own identity. Deep-merged over the scanned
        |     // @OA\Info; unspecified fields (version, contact, …) fall back to it.
        |     'info' => [
        |         'title'       => 'Langsys Integration API',
        |         'description' => 'The API-key-authenticated subset of the Langsys API.',
        |     ],
        | ],
        |
        | Every set drops components/tags no operation references, so docs never
        | ship unused schemas. Filtered sets always prune; on any other set you can
        | opt out to keep all your DTO schemas even when nothing references them:
        | 'v2' => [ ..., 'prune_unused_components' => false ],
        |
        | --- Unresolved $ref detection (opt-in) --------------------------------
        |
        | Catch $refs that point at a component which is never defined (e.g. an
        | @OA\Parameter(ref="#/components/parameters/tier") with no matching
        | definition block). Off by default. 'warn' lists them after generation;
        | 'strict' aborts generation so a broken spec is never written — ideal in CI.
        | 'default' => [ ..., 'validate_refs' => 'strict' ],
        */
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults (shared across all documentation sets)
    |--------------------------------------------------------------------------
    | Every documentation set inherits these. Per-documentation config
    | overrides via deep merge (associative arrays merged, scalars/indexed
    | arrays replaced).
    */
    'defaults' => [

        // --- DTO Schema Generation ---
        // DTOs are auto-discovered from the same directories as annotations.
        'dto' => [
            'faker_attribute_mapper' => [
                'address_1' => 'streetAddress',
                'address_2' => 'buildingNumber',
                'zip' => 'postcode',
                '_at' => 'date',
                '_url' => 'url',
                'locale' => 'locale',
                'phone' => 'phoneNumber',
                '_id' => 'id',
            ],

            'custom_functions' => [
                'id' => [\Langsys\OpenApiDocsGenerator\Functions\CustomFunctions::class, 'id'],
                'date' => [\Langsys\OpenApiDocsGenerator\Functions\CustomFunctions::class, 'date'],
            ],

            'pagination_fields' => [
                ['name' => 'status', 'description' => 'Response status', 'content' => true, 'type' => 'bool'],
                ['name' => 'page', 'description' => 'Current page number', 'content' => 1, 'type' => 'int'],
                ['name' => 'records_per_page', 'description' => 'Records per page', 'content' => 8, 'type' => 'int'],
                ['name' => 'page_count', 'description' => 'Number of pages', 'content' => 5, 'type' => 'int'],
                ['name' => 'total_records', 'description' => 'Total items', 'content' => 40, 'type' => 'int'],
            ],
        ],

        // --- API Errors ---
        // Every non-abstract subclass of `base_class` is an API error. Each one
        // declares its identity with class constants:
        //   public const CODE = 'not_found';             // declared by the class itself
        //   public const MESSAGE = 'Resource not found'; // declared by the class itself; also the docs text
        //   public const STATUS = 404;                   // int or int-backed enum; may be inherited
        // Its response is
        //   { status: false, data: [], error: { message, code, details, ... } }
        // For each error the documentation set's operations reference, the
        // generator emits the `{Name}` details schema, the `{Name}Body` error
        // object, the `{Name}Response` envelope, a reusable
        // `components.responses.{Name}` (with `x-http-status`), and a shared
        // string-enum schema of those codes that doubles as the error-codes
        // reference page. Unreferenced errors are left out even when
        // `prune_unused_components` is false.
        'errors' => [
            // The abstract base class your error classes extend (a class name, or
            // a list of them). null disables error documentation.
            // e.g. \App\Http\Errors\ApiError::class
            'base_class' => null,

            // Extra directories to scan for error classes. null = the same
            // directories as DTO discovery (`paths.annotations`).
            'paths' => null,

            // Name of the shared error-code enum schema.
            'code_schema' => 'ErrorCode',

            // Response-level field names. null omits a field; `error` cannot be null.
            //   status -> boolean, always false
            //   data   -> array, always empty (maxItems: 0)
            //   error  -> the `{Name}Body` error object
            'response_fields' => [
                'status' => 'status',
                'data' => 'data',
                'error' => 'error',
            ],

            // Field names inside the error object. null omits a field; `code` cannot be null.
            //   message  -> string, MESSAGE with {marker}s filled from #[Example] values as example
            //   code     -> string enum of the single code
            //   template -> string, MESSAGE verbatim: the source text a client translates
            //   params   -> object with one property per {marker} in MESSAGE, built from the
            //               same-named public property (omitted when MESSAGE has no markers)
            //   details  -> $ref to the `{Name}` details schema (omitted when the
            //               class has no non-#[EnvelopeField] properties)
            // Properties marked #[EnvelopeField] follow at the top level of the error object.
            'error_fields' => [
                'message' => 'message',
                'code' => 'code',
                'template' => 'template',
                'params' => 'params',
                'details' => 'details',
            ],
        ],

        // --- Implied Errors ---
        // Attach error responses to operations without hand-typing them. An
        // operation's errors are the union of its #[Throws(...)] classes and the
        // rules below, deduplicated by class. A status owned by one error becomes
        // a $ref to its reusable response; a status shared by several becomes a
        // oneOf discriminated on `code`. A response you wrote yourself for that
        // status always wins.
        'implied_errors' => [
            // Middleware alias or FQCN => the errors any route carrying it can
            // return. Matched against the route's fully-resolved middleware, so
            // it works however the middleware was attached.
            // e.g. 'auth:sanctum' => [\App\Http\Errors\UnauthenticatedError::class],
            'middleware' => [],

            // Error implied when the action takes a Spatie Data parameter
            // (i.e. the request is validated by Laravel Data).
            // e.g. \App\Http\Errors\ValidationFailedError::class
            'validation' => null,

            // Error implied when the route has a bound {param}.
            // e.g. \App\Http\Errors\NotFoundError::class
            'not_found' => null,

            // Which parameters count as "bound" for the rule above:
            //   'model' (default) — only params bound to an Eloquent model, either
            //                       implicitly by the action's signature or by Route::bind()
            //   'any'             — any {param} in the route URI
            'not_found_binding' => 'model',

            // Your own rules, for conventions the framework cannot prove — an
            // in-body authorization call, a permission registry, a service
            // contract. Each implements Contracts\ImpliedErrorRule and receives
            // the operation, its resolved route and its reflected action.
            // Descriptors: a class name, ['class' => X, 'args' => [...]], or an
            // instance.
            // e.g. ['class' => \App\Docs\AuthorizesRule::class, 'args' => [...]]
            'rules' => [],

            // Resolvers that list the field-level validation failures an endpoint can
            // return, so its validation response documents the actual outcomes instead
            // of one flat message. Each implements Contracts\ValidationScenarioResolver
            // and returns Data\ValidationScenario objects (a nullable field, a code and
            // a message). Same descriptor forms as `rules`. Requires `validation` above,
            // since the scenarios are listed on that error's response.
            // e.g. \App\Docs\FieldErrorScenarios::class
            'validation_scenarios' => [],
        ],

        // --- Output Paths ---
        'paths' => [
            'docs' => storage_path('api-docs'),
            'base' => env('OPENAPI_BASE_PATH', null),
            'excludes' => [],
        ],

        // --- Scan Options (for controller annotation scanning) ---
        'scan_options' => [
            // Custom processors run after swagger-php parses your annotations.
            // Common use case: control the order tags appear in Swagger UI.
            // Generate a starter processor with: php artisan openapi:make-processor
            // Example:
            //   'processors' => [new \App\Swagger\TagOrderProcessor()],
            'processors' => [],

            // Directories or files to skip when scanning for annotations.
            // Example: ['app/Http/Controllers/Internal']
            'exclude' => [],

            // OpenAPI specification version (3.0.0 or 3.1.0)
            'open_api_spec_version' => env('OPENAPI_SPEC_VERSION', '3.0.0'),
        ],

        // --- Security Definitions ---
        'security_definitions' => [
            'security_schemes' => [
                /*
                 * Examples:
                 *
                 * 'sanctum' => [
                 *     'type' => 'apiKey',
                 *     'description' => 'Enter token in format: Bearer <token>',
                 *     'name' => 'Authorization',
                 *     'in' => 'header',
                 * ],
                 *
                 * 'passport' => [
                 *     'type' => 'oauth2',
                 *     'flows' => [
                 *         'password' => [
                 *             'authorizationUrl' => '/oauth/authorize',
                 *             'tokenUrl' => '/oauth/token',
                 *             'refreshUrl' => '/oauth/token/refresh',
                 *             'scopes' => [],
                 *         ],
                 *     ],
                 * ],
                 */
            ],
            'security' => [
                /*
                 * Examples:
                 * ['sanctum' => []],
                 */
            ],
        ],

        // --- Generation Behavior ---
        'generate_yaml_copy' => env('OPENAPI_GENERATE_YAML', false),

        // --- Constants (defined as PHP constants for use in annotations) ---
        'constants' => [
            // e.g. 'API_HOST' => env('API_HOST', 'http://localhost'),
        ],

        // --- Endpoint Parameter Enrichment ---
        // Set 'enabled' => true to enrich order_by/filter_by query parameters
        // with endpoint-specific field lists from the database.
        // Requires api_resources tables. Endpoints without DB data keep their generic $refs.
        //
        // 'field_types_resolver' (optional) is a callable that takes a resource name
        // and returns array<string, array{type: string, nullable: bool}>. When set,
        // the generated filter_by description calls out which fields support
        // null/!null and which support comparison operators (>, <, >=, <=).
        // Example: fn (string $resource) => app(App\Services\ApiResourceService::class)
        //              ->getFieldTypesForResource($resource),
        'endpoint_parameters' => [
            'enabled' => false,
            // 'field_types_resolver' => null,
        ],

        // --- Thunder Client Collection Generation ---
        'thunder_client' => [
            // Thunder Client workspace root directory.
            // Collections are written to {output_dir}/collections/
            // Environment files are written to {output_dir}/
            'output_dir' => base_path('thunder-tests'),

            // Slug used in collection filename: tc_col_{slug}.json
            'collection_slug' => 'api',

            // Collection display name (null = use OpenAPI info.title)
            'collection_name' => null,

            // Base URL variable name (used as {{variable}} in URLs)
            'base_url_variable' => 'url',

            // Authentication schemes (keyed by name, matched to OpenAPI securitySchemes)
            'auth' => [
                'sanctum' => [
                    'type' => 'bearer',
                    'token_variable' => 'token',
                ],
                // 'api_key' => [
                //     'type' => 'header',
                //     'header_name' => 'X-Authorization',
                //     'value' => '{{api_key}}',
                // ],
            ],

            // Default auth to apply when an operation has no security defined
            'default_auth' => 'sanctum',

            // Environment generation (optional, set to null to skip)
            'environment' => [
                'slug' => 'local',
                'name' => 'Local',
                'variables' => [
                    'url' => 'env:APP_URL',
                ],
                'url_suffix' => '/api',
            ],

            // Path segments to skip when inferring folder names (fallback when no tags)
            'skip_path_segments' => ['api', 'v1', 'v2', 'v3'],

            // Default headers to include on every request
            'default_headers' => [
                ['name' => 'Accept', 'value' => 'application/json'],
            ],
        ],
    ],
];
