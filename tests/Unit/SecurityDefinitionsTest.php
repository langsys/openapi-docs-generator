<?php

use Langsys\OpenApiDocsGenerator\Generators\SecurityDefinitions;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/openapi-tests-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->docsFile = $this->tempDir . '/api-docs.json';
});

afterEach(function () {
    if (file_exists($this->docsFile)) {
        unlink($this->docsFile);
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

test('it injects security schemes into empty components', function () {
    file_put_contents($this->docsFile, json_encode([
        'openapi' => '3.0.0',
        'info' => ['title' => 'Test', 'version' => '1.0'],
        'paths' => [],
    ]));

    $security = new SecurityDefinitions(
        securitySchemesConfig: [
            'sanctum' => [
                'type' => 'apiKey',
                'description' => 'Bearer token',
                'name' => 'Authorization',
                'in' => 'header',
            ],
        ],
        securityConfig: [],
    );

    $security->generate($this->docsFile);

    $result = json_decode(file_get_contents($this->docsFile), true);

    expect($result['components']['securitySchemes']['sanctum'])->toBe([
        'type' => 'apiKey',
        'description' => 'Bearer token',
        'name' => 'Authorization',
        'in' => 'header',
    ]);
});

test('it injects security requirements', function () {
    file_put_contents($this->docsFile, json_encode([
        'openapi' => '3.0.0',
        'info' => ['title' => 'Test', 'version' => '1.0'],
        'paths' => [],
    ]));

    $security = new SecurityDefinitions(
        securitySchemesConfig: [],
        securityConfig: [
            ['sanctum' => []],
        ],
    );

    $security->generate($this->docsFile);

    $result = json_decode(file_get_contents($this->docsFile), true);

    expect($result['security'])->toHaveCount(1)
        ->and($result['security'][0])->toBe(['sanctum' => []]);
});

test('it does not overwrite existing annotation-defined security schemes', function () {
    file_put_contents($this->docsFile, json_encode([
        'openapi' => '3.0.0',
        'info' => ['title' => 'Test', 'version' => '1.0'],
        'paths' => [],
        'components' => [
            'securitySchemes' => [
                'sanctum' => [
                    'type' => 'apiKey',
                    'name' => 'Authorization',
                    'in' => 'header',
                    'description' => 'Original from annotations',
                ],
            ],
        ],
    ]));

    $security = new SecurityDefinitions(
        securitySchemesConfig: [
            'sanctum' => [
                'type' => 'apiKey',
                'name' => 'Authorization',
                'in' => 'header',
                'description' => 'From config - should NOT overwrite',
            ],
        ],
        securityConfig: [],
    );

    $security->generate($this->docsFile);

    $result = json_decode(file_get_contents($this->docsFile), true);

    expect($result['components']['securitySchemes']['sanctum']['description'])
        ->toBe('Original from annotations');
});

test('it does not duplicate existing security requirements', function () {
    file_put_contents($this->docsFile, json_encode([
        'openapi' => '3.0.0',
        'info' => ['title' => 'Test', 'version' => '1.0'],
        'paths' => [],
        'security' => [
            ['sanctum' => []],
        ],
    ]));

    $security = new SecurityDefinitions(
        securitySchemesConfig: [],
        securityConfig: [
            ['sanctum' => []],
        ],
    );

    $security->generate($this->docsFile);

    $result = json_decode(file_get_contents($this->docsFile), true);

    expect($result['security'])->toHaveCount(1);
});

test('it adds multiple security schemes', function () {
    file_put_contents($this->docsFile, json_encode([
        'openapi' => '3.0.0',
        'info' => ['title' => 'Test', 'version' => '1.0'],
        'paths' => [],
    ]));

    $security = new SecurityDefinitions(
        securitySchemesConfig: [
            'sanctum' => [
                'type' => 'apiKey',
                'name' => 'Authorization',
                'in' => 'header',
            ],
            'oauth2' => [
                'type' => 'oauth2',
                'flows' => [
                    'password' => [
                        'tokenUrl' => '/oauth/token',
                        'scopes' => [],
                    ],
                ],
            ],
        ],
        securityConfig: [
            ['sanctum' => []],
            ['oauth2' => []],
        ],
    );

    $security->generate($this->docsFile);

    $result = json_decode(file_get_contents($this->docsFile), true);

    expect($result['components']['securitySchemes'])->toHaveCount(2)
        ->and($result['components']['securitySchemes'])->toHaveKeys(['sanctum', 'oauth2'])
        ->and($result['security'])->toHaveCount(2);
});

test('it does nothing when both configs are empty', function () {
    $original = json_encode([
        'openapi' => '3.0.0',
        'info' => ['title' => 'Test', 'version' => '1.0'],
        'paths' => [],
    ]);
    file_put_contents($this->docsFile, $original);

    $security = new SecurityDefinitions(
        securitySchemesConfig: [],
        securityConfig: [],
    );

    $security->generate($this->docsFile);

    $result = json_decode(file_get_contents($this->docsFile), true);

    expect($result)->not->toHaveKey('components')
        ->and($result)->not->toHaveKey('security');
});

test('output JSON is pretty-printed with unescaped slashes', function () {
    file_put_contents($this->docsFile, json_encode([
        'openapi' => '3.0.0',
        'info' => ['title' => 'Test', 'version' => '1.0'],
        'paths' => ['/api/users' => []],
    ]));

    $security = new SecurityDefinitions(
        securitySchemesConfig: [
            'sanctum' => ['type' => 'apiKey', 'name' => 'Authorization', 'in' => 'header'],
        ],
        securityConfig: [],
    );

    $security->generate($this->docsFile);

    $raw = file_get_contents($this->docsFile);

    // Pretty-printed = has newlines
    expect($raw)->toContain("\n");
    // Unescaped slashes
    expect($raw)->toContain('/api/users');
    expect($raw)->not->toContain('\\/api\\/users');
});
