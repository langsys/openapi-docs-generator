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

        // --- Output Paths ---
        'paths' => [
            'docs' => storage_path('api-docs'),
            'base' => env('OPENAPI_BASE_PATH', null),
            'excludes' => [],
        ],

        // --- Scan Options (for controller annotation scanning) ---
        'scan_options' => [
            'default_processors_configuration' => [],
            'analyser' => null,
            'analysis' => null,
            'processors' => [],
            'pattern' => null,
            'exclude' => [],
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
        'endpoint_parameters' => [
            'enabled' => false,
        ],
    ],
];
