<?php

use Illuminate\Console\Command;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/openapi-exit-' . uniqid();
    $dangling = dirname(__DIR__, 2) . '/tests/DanglingFixtures';

    config()->set('openapi-docs.default', 'clean');
    config()->set('openapi-docs.defaults', [
        'paths' => [
            'annotations' => [$dangling],
            'docs' => $this->tempDir,
            'docs_json' => 'api-docs.json',
            'docs_yaml' => 'api-docs.yaml',
        ],
        'dto' => [],
        'scan_options' => ['open_api_spec_version' => '3.0.0'],
        'security_definitions' => ['security_schemes' => [], 'security' => []],
        'generate_yaml_copy' => false,
    ]);
    // Both sets scan the deliberately-dangling fixture; only 'broken' validates
    // strictly, so only it aborts. Each writes to its own dir.
    config()->set('openapi-docs.documentations', [
        'clean' => ['validate_refs' => 'off', 'paths' => ['docs' => $this->tempDir . '/clean']],
        'broken' => ['validate_refs' => 'strict', 'paths' => ['docs' => $this->tempDir . '/broken']],
    ]);
});

afterEach(function () {
    foreach (['clean', 'broken'] as $sub) {
        @unlink($this->tempDir . "/{$sub}/api-docs.json");
        @unlink($this->tempDir . "/{$sub}/api-docs.yaml");
        @rmdir($this->tempDir . "/{$sub}");
    }
    @rmdir($this->tempDir);
});

test('a set that aborts under validate_refs strict makes the command exit non-zero', function () {
    $this->artisan('openapi:generate', ['documentation' => 'broken'])
        ->assertExitCode(Command::FAILURE);

    expect(file_exists($this->tempDir . '/broken/api-docs.json'))->toBeFalse();
});

test('a set that generates without failing exits zero', function () {
    $this->artisan('openapi:generate', ['documentation' => 'clean'])
        ->assertExitCode(Command::SUCCESS);

    expect(file_exists($this->tempDir . '/clean/api-docs.json'))->toBeTrue();
});

test('--all exits non-zero when any set fails, but successful sets still write', function () {
    $this->artisan('openapi:generate', ['--all' => true])
        ->assertExitCode(Command::FAILURE);

    expect(file_exists($this->tempDir . '/clean/api-docs.json'))->toBeTrue()
        ->and(file_exists($this->tempDir . '/broken/api-docs.json'))->toBeFalse();
});
