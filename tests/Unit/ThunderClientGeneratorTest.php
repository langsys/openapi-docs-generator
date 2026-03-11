<?php

use Langsys\OpenApiDocsGenerator\Generators\ThunderClientGenerator;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/thunder-client-test-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->openApiFile = $this->tempDir . '/api-docs.json';
    $this->collectionFile = $this->tempDir . '/tc_col_api.json';
    $this->envFile = $this->tempDir . '/tc_env_local.json';
});

afterEach(function () {
    $files = glob($this->tempDir . '/*');
    foreach ($files as $file) {
        @unlink($file);
    }
    @rmdir($this->tempDir);
});

function writeOpenApi(string $path, array $spec = []): void
{
    $data = array_merge([
        'openapi' => '3.0.0',
        'info' => ['title' => 'Test API', 'version' => '1.0.0'],
        'paths' => [
            '/api/users' => [
                'get' => [
                    'summary' => 'List Users',
                    'tags' => ['Users'],
                    'responses' => ['200' => ['description' => 'OK']],
                ],
            ],
        ],
    ], $spec);

    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
}

function makeThunderGenerator(
    string $openApiFile,
    string $collectionFile,
    ?string $collectionName = null,
    array $authSchemes = [],
    string $defaultAuth = 'none',
    string $baseUrlVariable = 'url',
    array $skipPathSegments = ['api', 'v1', 'v2', 'v3'],
    array $defaultHeaders = [['name' => 'Accept', 'value' => 'application/json']],
    ?array $environmentConfig = null,
    ?string $environmentFile = null,
): ThunderClientGenerator {
    return new ThunderClientGenerator(
        openApiFile: $openApiFile,
        collectionFile: $collectionFile,
        collectionName: $collectionName,
        authSchemes: $authSchemes,
        defaultAuth: $defaultAuth,
        baseUrlVariable: $baseUrlVariable,
        skipPathSegments: $skipPathSegments,
        defaultHeaders: $defaultHeaders,
        environmentConfig: $environmentConfig,
        environmentFile: $environmentFile,
    );
}

// --- Basic Generation ---

test('generates valid tc_col json with correct root structure', function () {
    writeOpenApi($this->openApiFile);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    expect(file_exists($this->collectionFile))->toBeTrue();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    expect($col)->toHaveKey('_id')
        ->and($col)->toHaveKey('colName')
        ->and($col)->toHaveKey('created')
        ->and($col)->toHaveKey('sortNum')
        ->and($col)->toHaveKey('folders')
        ->and($col)->toHaveKey('requests')
        ->and($col['colName'])->toBe('Test API');
});

test('converts {param} to {{param}} in URLs', function () {
    $openApi = [
        'openapi' => '3.0.0',
        'info' => ['title' => 'Test API', 'version' => '1.0.0'],
        'paths' => [
            '/api/users/{id}' => [
                'get' => [
                    'summary' => 'Get User',
                    'tags' => ['Users'],
                    'responses' => ['200' => ['description' => 'OK']],
                ],
            ],
        ],
    ];
    file_put_contents($this->openApiFile, json_encode($openApi, JSON_PRETTY_PRINT));

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    expect($col['requests'][0]['url'])->toBe('{{url}}/api/users/{{id}}');
});

test('prefixes URLs with configurable base_url_variable', function () {
    writeOpenApi($this->openApiFile);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile, baseUrlVariable: 'base');
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    expect($col['requests'][0]['url'])->toStartWith('{{base}}');
});

// --- Folder Grouping ---

test('groups requests into folders by OpenAPI tags', function () {
    writeOpenApi($this->openApiFile, [
        'paths' => [
            '/api/users' => [
                'get' => ['summary' => 'List Users', 'tags' => ['Users'], 'responses' => ['200' => ['description' => 'OK']]],
            ],
            '/api/posts' => [
                'get' => ['summary' => 'List Posts', 'tags' => ['Posts'], 'responses' => ['200' => ['description' => 'OK']]],
            ],
        ],
    ]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    $folderNames = array_column($col['folders'], 'name');
    expect($folderNames)->toContain('Users')
        ->and($folderNames)->toContain('Posts')
        ->and($col['folders'])->toHaveCount(2);

    // Requests should reference their folder
    foreach ($col['requests'] as $req) {
        expect($req['containerId'])->not->toBe('');
    }
});

test('falls back to path segment inference when no tags', function () {
    writeOpenApi($this->openApiFile, [
        'paths' => [
            '/api/users' => [
                'get' => ['summary' => 'List Users', 'responses' => ['200' => ['description' => 'OK']]],
            ],
        ],
    ]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    $folderNames = array_column($col['folders'], 'name');
    expect($folderNames)->toContain('Users');
});

test('skips configured path segments for folder names', function () {
    writeOpenApi($this->openApiFile, [
        'paths' => [
            '/api/v1/projects' => [
                'get' => ['summary' => 'List Projects', 'responses' => ['200' => ['description' => 'OK']]],
            ],
        ],
    ]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile, skipPathSegments: ['api', 'v1']);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    $folderNames = array_column($col['folders'], 'name');
    expect($folderNames)->toContain('Projects');
});

// --- Request Name ---

test('uses operation summary as request name', function () {
    writeOpenApi($this->openApiFile);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    expect($col['requests'][0]['name'])->toBe('List Users');
});

test('falls back to METHOD /path when no summary', function () {
    $openApi = [
        'openapi' => '3.0.0',
        'info' => ['title' => 'Test API', 'version' => '1.0.0'],
        'paths' => [
            '/api/users' => [
                'get' => ['responses' => ['200' => ['description' => 'OK']]],
            ],
        ],
    ];
    file_put_contents($this->openApiFile, json_encode($openApi, JSON_PRETTY_PRINT));

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    expect($col['requests'][0]['name'])->toBe('GET /api/users');
});

// --- Request Body ---

test('builds request body from schema examples with ref resolution', function () {
    writeOpenApi($this->openApiFile, [
        'paths' => [
            '/api/users' => [
                'post' => [
                    'summary' => 'Create User',
                    'tags' => ['Users'],
                    'requestBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/CreateUser'],
                            ],
                        ],
                    ],
                    'responses' => ['201' => ['description' => 'Created']],
                ],
            ],
        ],
        'components' => [
            'schemas' => [
                'CreateUser' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'example' => 'John Doe'],
                        'email' => ['type' => 'string', 'example' => 'john@example.com'],
                        'age' => ['type' => 'integer'],
                        'active' => ['type' => 'boolean'],
                    ],
                ],
            ],
        ],
    ]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    $body = json_decode($col['requests'][0]['body']['raw'], true);
    expect($body['name'])->toBe('John Doe')
        ->and($body['email'])->toBe('john@example.com')
        ->and($body['age'])->toBe(0)
        ->and($body['active'])->toBe(false);
});

test('uses first enum value in body construction', function () {
    writeOpenApi($this->openApiFile, [
        'paths' => [
            '/api/users' => [
                'post' => [
                    'summary' => 'Create User',
                    'tags' => ['Users'],
                    'requestBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'role' => ['type' => 'string', 'enum' => ['admin', 'user', 'guest']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => ['201' => ['description' => 'Created']],
                ],
            ],
        ],
    ]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    $body = json_decode($col['requests'][0]['body']['raw'], true);
    expect($body['role'])->toBe('admin');
});

test('handles allOf in request body schema', function () {
    writeOpenApi($this->openApiFile, [
        'paths' => [
            '/api/users' => [
                'post' => [
                    'summary' => 'Create User',
                    'tags' => ['Users'],
                    'requestBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'allOf' => [
                                        [
                                            'type' => 'object',
                                            'properties' => [
                                                'name' => ['type' => 'string', 'example' => 'Jane'],
                                            ],
                                        ],
                                        [
                                            'type' => 'object',
                                            'properties' => [
                                                'email' => ['type' => 'string', 'example' => 'jane@test.com'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => ['201' => ['description' => 'Created']],
                ],
            ],
        ],
    ]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    $body = json_decode($col['requests'][0]['body']['raw'], true);
    expect($body)->toHaveKey('name')
        ->and($body)->toHaveKey('email');
});

// --- Auth Handling ---

test('sets bearer auth on requests', function () {
    writeOpenApi($this->openApiFile, [
        'security' => [['sanctum' => []]],
    ]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile, authSchemes: [
        'sanctum' => ['type' => 'bearer', 'token_variable' => 'token'],
    ]);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    expect($col['requests'][0]['auth'])->toBe(['type' => 'bearer']);
});

test('sets custom header auth on requests', function () {
    writeOpenApi($this->openApiFile, [
        'security' => [['api_key' => []]],
    ]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile, authSchemes: [
        'api_key' => ['type' => 'header', 'header_name' => 'X-Authorization', 'value' => '{{api_key}}'],
    ]);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    $req = $col['requests'][0];
    expect($req['auth'])->toBe(['type' => 'none']);

    $headerNames = array_column($req['headers'], 'name');
    expect($headerNames)->toContain('X-Authorization');

    $authHeader = collect($req['headers'])->firstWhere('name', 'X-Authorization');
    expect($authHeader['value'])->toBe('{{api_key}}');
});

test('creates one request per security scheme when multiple schemes', function () {
    writeOpenApi($this->openApiFile, [
        'security' => [['sanctum' => []], ['api_key' => []]],
    ]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile, authSchemes: [
        'sanctum' => ['type' => 'bearer', 'token_variable' => 'token'],
        'api_key' => ['type' => 'header', 'header_name' => 'X-Auth', 'value' => '{{key}}'],
    ]);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    expect($col['requests'])->toHaveCount(2);
});

test('suffixes request name with scheme name when multiple schemes', function () {
    writeOpenApi($this->openApiFile, [
        'security' => [['sanctum' => []], ['api_key' => []]],
    ]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile, authSchemes: [
        'sanctum' => ['type' => 'bearer'],
        'api_key' => ['type' => 'header', 'header_name' => 'X-Auth', 'value' => '{{key}}'],
    ]);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    $names = array_column($col['requests'], 'name');
    expect($names)->toContain('List Users (sanctum)')
        ->and($names)->toContain('List Users (api_key)');
});

test('no suffix when single scheme', function () {
    writeOpenApi($this->openApiFile, [
        'security' => [['sanctum' => []]],
    ]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile, authSchemes: [
        'sanctum' => ['type' => 'bearer'],
    ]);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    expect($col['requests'][0]['name'])->toBe('List Users');
});

test('uses default_auth when operation has no security defined', function () {
    writeOpenApi($this->openApiFile); // no global security

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile, authSchemes: [
        'sanctum' => ['type' => 'bearer'],
    ], defaultAuth: 'sanctum');
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    expect($col['requests'][0]['auth'])->toBe(['type' => 'bearer']);
});

test('skips unknown scheme names gracefully', function () {
    writeOpenApi($this->openApiFile, [
        'security' => [['unknown_scheme' => []]],
    ]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile, authSchemes: []);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    // Falls back to auth none since the only scheme is unknown
    expect($col['requests'][0]['auth'])->toBe(['type' => 'none']);
    expect($gen->getWarnings())->toContain("Unknown auth scheme 'unknown_scheme' — skipping.");
});

// --- Merge / No-Overwrite ---

test('does NOT overwrite existing requests', function () {
    writeOpenApi($this->openApiFile);

    // Generate initial collection
    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col1 = json_decode(file_get_contents($this->collectionFile), true);
    $originalRequestId = $col1['requests'][0]['_id'];

    // Generate again — should not duplicate
    $gen2 = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen2->generate();

    $col2 = json_decode(file_get_contents($this->collectionFile), true);
    expect($col2['requests'])->toHaveCount(1)
        ->and($col2['requests'][0]['_id'])->toBe($originalRequestId);
});

test('preserves existing collection _id, folders, requests on merge', function () {
    writeOpenApi($this->openApiFile);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col1 = json_decode(file_get_contents($this->collectionFile), true);
    $originalColId = $col1['_id'];
    $originalFolders = $col1['folders'];

    // Add a new endpoint to the OpenAPI spec
    writeOpenApi($this->openApiFile, [
        'paths' => [
            '/api/users' => [
                'get' => ['summary' => 'List Users', 'tags' => ['Users'], 'responses' => ['200' => ['description' => 'OK']]],
            ],
            '/api/posts' => [
                'get' => ['summary' => 'List Posts', 'tags' => ['Posts'], 'responses' => ['200' => ['description' => 'OK']]],
            ],
        ],
    ]);

    $gen2 = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen2->generate();

    $col2 = json_decode(file_get_contents($this->collectionFile), true);
    expect($col2['_id'])->toBe($originalColId)
        ->and($col2['requests'])->toHaveCount(2);

    // Original folder preserved
    $folderNames = array_column($col2['folders'], 'name');
    expect($folderNames)->toContain('Users')
        ->and($folderNames)->toContain('Posts');
});

test('adds only new folders for new requests on merge', function () {
    writeOpenApi($this->openApiFile);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col1 = json_decode(file_get_contents($this->collectionFile), true);
    expect($col1['folders'])->toHaveCount(1);

    writeOpenApi($this->openApiFile, [
        'paths' => [
            '/api/users' => [
                'get' => ['summary' => 'List Users', 'tags' => ['Users'], 'responses' => ['200' => ['description' => 'OK']]],
            ],
            '/api/orders' => [
                'get' => ['summary' => 'List Orders', 'tags' => ['Orders'], 'responses' => ['200' => ['description' => 'OK']]],
            ],
        ],
    ]);

    $gen2 = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen2->generate();

    $col2 = json_decode(file_get_contents($this->collectionFile), true);
    expect($col2['folders'])->toHaveCount(2);
});

// --- Edge Cases ---

test('handles empty paths gracefully', function () {
    writeOpenApi($this->openApiFile, ['paths' => []]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    expect($col['requests'])->toBeEmpty()
        ->and($col['folders'])->toBeEmpty();
});

test('uses config collection_name over OpenAPI info.title', function () {
    writeOpenApi($this->openApiFile);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile, collectionName: 'My Custom API');
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    expect($col['colName'])->toBe('My Custom API');
});

test('only processes documented endpoints present in OpenAPI paths', function () {
    writeOpenApi($this->openApiFile, [
        'paths' => [
            '/api/users' => [
                'get' => ['summary' => 'List Users', 'tags' => ['Users'], 'responses' => ['200' => ['description' => 'OK']]],
            ],
        ],
    ]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    // Only one endpoint in paths → only one request
    expect($col['requests'])->toHaveCount(1)
        ->and($col['requests'][0]['name'])->toBe('List Users');
});

test('adds Content-Type header for POST/PUT/PATCH', function () {
    writeOpenApi($this->openApiFile, [
        'paths' => [
            '/api/users' => [
                'post' => ['summary' => 'Create User', 'tags' => ['Users'], 'responses' => ['201' => ['description' => 'Created']]],
            ],
        ],
    ]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    $headerNames = array_column($col['requests'][0]['headers'], 'name');
    expect($headerNames)->toContain('Content-Type')
        ->and($headerNames)->toContain('Accept');
});

test('does not add Content-Type header for GET requests', function () {
    writeOpenApi($this->openApiFile);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    $headerNames = array_column($col['requests'][0]['headers'], 'name');
    expect($headerNames)->not->toContain('Content-Type');
});

// --- Environment Generation ---

test('generates environment file when configured', function () {
    writeOpenApi($this->openApiFile);

    $gen = makeThunderGenerator(
        $this->openApiFile,
        $this->collectionFile,
        environmentConfig: [
            'slug' => 'local',
            'name' => 'Local',
            'variables' => [
                'url' => 'http://localhost/api',
                'token' => '',
            ],
        ],
        environmentFile: $this->envFile,
    );
    $gen->generate();

    expect(file_exists($this->envFile))->toBeTrue();

    $env = json_decode(file_get_contents($this->envFile), true);
    expect($env)->toHaveKey('_id')
        ->and($env['name'])->toBe('Local')
        ->and($env['default'])->toBeTrue()
        ->and($env['data'])->toHaveCount(2);

    $varNames = array_column($env['data'], 'name');
    expect($varNames)->toContain('url')
        ->and($varNames)->toContain('token');
});

test('does NOT overwrite existing environment file', function () {
    writeOpenApi($this->openApiFile);

    // Create initial env file
    file_put_contents($this->envFile, json_encode(['_id' => 'existing', 'name' => 'Existing']));

    $gen = makeThunderGenerator(
        $this->openApiFile,
        $this->collectionFile,
        environmentConfig: ['slug' => 'local', 'name' => 'Overwritten', 'variables' => []],
        environmentFile: $this->envFile,
    );
    $gen->generate();

    $env = json_decode(file_get_contents($this->envFile), true);
    expect($env['name'])->toBe('Existing');
});

test('skips environment generation when config is null', function () {
    writeOpenApi($this->openApiFile);

    $gen = makeThunderGenerator(
        $this->openApiFile,
        $this->collectionFile,
        environmentConfig: null,
        environmentFile: null,
    );
    $gen->generate();

    expect(file_exists($this->envFile))->toBeFalse();
});

test('resolves env: prefix variables from .env', function () {
    writeOpenApi($this->openApiFile);

    // Set an env variable that Laravel's env() can read
    putenv('TC_TEST_APP_URL=http://myapp.test');

    $gen = makeThunderGenerator(
        $this->openApiFile,
        $this->collectionFile,
        environmentConfig: [
            'slug' => 'local',
            'name' => 'Local',
            'variables' => [
                'url' => 'env:TC_TEST_APP_URL',
            ],
            'url_suffix' => '/api',
        ],
        environmentFile: $this->envFile,
    );
    $gen->generate();

    $env = json_decode(file_get_contents($this->envFile), true);
    $urlVar = collect($env['data'])->firstWhere('name', 'url');
    expect($urlVar['value'])->toBe('http://myapp.test/api');

    putenv('TC_TEST_APP_URL');
});

test('appends url_suffix to base URL variable', function () {
    writeOpenApi($this->openApiFile);

    putenv('TC_TEST_URL=http://localhost');

    $gen = makeThunderGenerator(
        $this->openApiFile,
        $this->collectionFile,
        environmentConfig: [
            'slug' => 'local',
            'name' => 'Local',
            'variables' => [
                'url' => 'env:TC_TEST_URL',
            ],
            'url_suffix' => '/api',
        ],
        environmentFile: $this->envFile,
    );
    $gen->generate();

    $env = json_decode(file_get_contents($this->envFile), true);
    $urlVar = collect($env['data'])->firstWhere('name', 'url');
    expect($urlVar['value'])->toBe('http://localhost/api');

    putenv('TC_TEST_URL');
});

test('literal values are not modified by url_suffix', function () {
    writeOpenApi($this->openApiFile);

    $gen = makeThunderGenerator(
        $this->openApiFile,
        $this->collectionFile,
        environmentConfig: [
            'slug' => 'local',
            'name' => 'Local',
            'variables' => [
                'url' => 'http://literal.test',
            ],
            'url_suffix' => '/api',
        ],
        environmentFile: $this->envFile,
    );
    $gen->generate();

    $env = json_decode(file_get_contents($this->envFile), true);
    $urlVar = collect($env['data'])->firstWhere('name', 'url');
    // url_suffix only applies when value starts with env:
    expect($urlVar['value'])->toBe('http://literal.test');
});

// --- Missing OpenAPI file ---

test('handles missing OpenAPI file gracefully', function () {
    $gen = makeThunderGenerator($this->tempDir . '/nonexistent.json', $this->collectionFile);
    $gen->generate();

    expect(file_exists($this->collectionFile))->toBeFalse();
    expect($gen->getWarnings())->not->toBeEmpty();
});

// --- Request body raw is a string ---

test('body raw is a JSON string not an object', function () {
    writeOpenApi($this->openApiFile, [
        'paths' => [
            '/api/users' => [
                'post' => [
                    'summary' => 'Create User',
                    'tags' => ['Users'],
                    'requestBody' => [
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name' => ['type' => 'string', 'example' => 'Test'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => ['201' => ['description' => 'Created']],
                ],
            ],
        ],
    ]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    expect($col['requests'][0]['body']['raw'])->toBeString();
    expect($col['requests'][0]['body']['type'])->toBe('json');
    expect($col['requests'][0]['body']['form'])->toBe([]);
});

// --- Multiple HTTP methods on same path ---

test('generates requests for multiple HTTP methods on same path', function () {
    writeOpenApi($this->openApiFile, [
        'paths' => [
            '/api/users' => [
                'get' => ['summary' => 'List Users', 'tags' => ['Users'], 'responses' => ['200' => ['description' => 'OK']]],
                'post' => ['summary' => 'Create User', 'tags' => ['Users'], 'responses' => ['201' => ['description' => 'Created']]],
            ],
        ],
    ]);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    expect($col['requests'])->toHaveCount(2);

    $methods = array_column($col['requests'], 'method');
    expect($methods)->toContain('GET')
        ->and($methods)->toContain('POST');
});

test('request has correct colId matching collection _id', function () {
    writeOpenApi($this->openApiFile);

    $gen = makeThunderGenerator($this->openApiFile, $this->collectionFile);
    $gen->generate();

    $col = json_decode(file_get_contents($this->collectionFile), true);
    expect($col['requests'][0]['colId'])->toBe($col['_id']);
});
