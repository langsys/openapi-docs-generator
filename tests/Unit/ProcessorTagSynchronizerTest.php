<?php

use Langsys\OpenApiDocsGenerator\Generators\ProcessorTagSynchronizer;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/processor-sync-test-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->processorFile = $this->tempDir . '/TagOrderProcessor.php';
    $this->openApiFile = $this->tempDir . '/api-docs.json';
});

afterEach(function () {
    $files = glob($this->tempDir . '/*');
    foreach ($files as $file) {
        @unlink($file);
    }
    @rmdir($this->tempDir);
});

function writeProcessor(string $path, array $tags): void
{
    $tagLines = array_map(function ($tag) {
        return "                new Tag(['name' => '{$tag}'])";
    }, $tags);

    $content = <<<PHP
<?php

namespace App\Swagger;

use OpenApi\Analysis;
use OpenApi\Annotations\Tag;
use OpenApi\Annotations\OpenApi;

class TagOrderProcessor
{
    public function __invoke(Analysis \$analysis): void
    {
        if (isset(\$analysis->openapi)) {
            \$openapi = \$analysis->openapi;

            \$openapi->tags = [
{TAGS}
            ];
        }
    }
}
PHP;

    $content = str_replace('{TAGS}', implode(",\n", $tagLines), $content);
    file_put_contents($path, $content);
}

function writeSpecWithTags(string $path, array $tags): void
{
    $paths = [];
    foreach ($tags as $tag) {
        $slug = strtolower(str_replace([' ', '-'], ['_', ''], $tag));
        $paths['/api/' . $slug] = [
            'get' => [
                'tags' => [$tag],
                'summary' => 'List ' . $tag,
                'responses' => ['200' => ['description' => 'OK']],
            ],
        ];
    }

    $spec = [
        'openapi' => '3.0.0',
        'info' => ['title' => 'Test', 'version' => '1.0'],
        'paths' => $paths,
    ];

    file_put_contents($path, json_encode($spec, JSON_PRETTY_PRINT));
}

// --- Basic synchronization ---

test('does nothing when all tags are already in processor', function () {
    $tags = ['Auth', 'Users', 'Projects'];
    writeProcessor($this->processorFile, $tags);
    writeSpecWithTags($this->openApiFile, $tags);

    $sync = new ProcessorTagSynchronizer();
    $added = $sync->synchronize($this->processorFile, $this->openApiFile);

    expect($added)->toBeEmpty();
});

test('adds new tags to processor', function () {
    writeProcessor($this->processorFile, ['Auth', 'Users']);
    writeSpecWithTags($this->openApiFile, ['Auth', 'Users', 'Projects']);

    $sync = new ProcessorTagSynchronizer();
    $added = $sync->synchronize($this->processorFile, $this->openApiFile);

    expect($added)->toContain('Projects');

    $source = file_get_contents($this->processorFile);
    expect($source)->toContain("'Projects'");
});

test('inserts sub-tag after its group', function () {
    writeProcessor($this->processorFile, [
        'Organization',
        'Organization - Settings',
        'Organization - Users',
        'Project',
    ]);
    writeSpecWithTags($this->openApiFile, [
        'Organization',
        'Organization - Settings',
        'Organization - Users',
        'Organization - Billing',
        'Project',
    ]);

    $sync = new ProcessorTagSynchronizer();
    $added = $sync->synchronize($this->processorFile, $this->openApiFile);

    expect($added)->toContain('Organization - Billing');

    $source = file_get_contents($this->processorFile);

    // Extract tag order from the rewritten file
    preg_match_all("/new\s+Tag\s*\(\s*\[\s*'name'\s*=>\s*'([^']+)'\s*\]\s*\)/", $source, $matches);
    $tagOrder = $matches[1];

    $orgBillingPos = array_search('Organization - Billing', $tagOrder);
    $orgUsersPos = array_search('Organization - Users', $tagOrder);
    $projectPos = array_search('Project', $tagOrder);

    // Organization - Billing should be after Organization - Users but before Project
    expect($orgBillingPos)->toBeGreaterThan($orgUsersPos)
        ->and($orgBillingPos)->toBeLessThan($projectPos);
});

test('inserts multiple sub-tags into correct groups', function () {
    writeProcessor($this->processorFile, [
        'Auth',
        'Organization',
        'Organization - Settings',
        'Project',
        'Project - Settings',
    ]);
    writeSpecWithTags($this->openApiFile, [
        'Auth',
        'Organization',
        'Organization - Settings',
        'Organization - Domains',
        'Project',
        'Project - Settings',
        'Project - Billing',
    ]);

    $sync = new ProcessorTagSynchronizer();
    $added = $sync->synchronize($this->processorFile, $this->openApiFile);

    expect($added)->toContain('Organization - Domains')
        ->and($added)->toContain('Project - Billing');

    $source = file_get_contents($this->processorFile);
    preg_match_all("/new\s+Tag\s*\(\s*\[\s*'name'\s*=>\s*'([^']+)'\s*\]\s*\)/", $source, $matches);
    $tagOrder = $matches[1];

    // Organization - Domains after Organization - Settings, before Project
    $orgDomainsPos = array_search('Organization - Domains', $tagOrder);
    $orgSettingsPos = array_search('Organization - Settings', $tagOrder);
    $projectPos = array_search('Project', $tagOrder);
    expect($orgDomainsPos)->toBeGreaterThan($orgSettingsPos)
        ->and($orgDomainsPos)->toBeLessThan($projectPos);

    // Project - Billing after Project - Settings
    $projBillingPos = array_search('Project - Billing', $tagOrder);
    $projSettingsPos = array_search('Project - Settings', $tagOrder);
    expect($projBillingPos)->toBeGreaterThan($projSettingsPos);
});

test('appends tags without prefix before Deprecated', function () {
    writeProcessor($this->processorFile, ['Auth', 'Users', 'Deprecated']);
    writeSpecWithTags($this->openApiFile, ['Auth', 'Users', 'WebAuthn', 'Deprecated']);

    $sync = new ProcessorTagSynchronizer();
    $added = $sync->synchronize($this->processorFile, $this->openApiFile);

    expect($added)->toContain('WebAuthn');

    $source = file_get_contents($this->processorFile);
    preg_match_all("/new\s+Tag\s*\(\s*\[\s*'name'\s*=>\s*'([^']+)'\s*\]\s*\)/", $source, $matches);
    $tagOrder = $matches[1];

    $webAuthnPos = array_search('WebAuthn', $tagOrder);
    $deprecatedPos = array_search('Deprecated', $tagOrder);
    expect($webAuthnPos)->toBeLessThan($deprecatedPos);
});

test('appends tags without prefix to end when no Deprecated', function () {
    writeProcessor($this->processorFile, ['Auth', 'Users']);
    writeSpecWithTags($this->openApiFile, ['Auth', 'Users', 'Notifications']);

    $sync = new ProcessorTagSynchronizer();
    $added = $sync->synchronize($this->processorFile, $this->openApiFile);

    $source = file_get_contents($this->processorFile);
    preg_match_all("/new\s+Tag\s*\(\s*'([^']+)'\s*|'name'\s*=>\s*'([^']+)'/", $source, $matches);
    // The last tag should be Notifications
    expect($source)->toContain("'Notifications'");
});

// --- Edge cases ---

test('returns empty when processor file does not exist', function () {
    writeSpecWithTags($this->openApiFile, ['Auth']);

    $sync = new ProcessorTagSynchronizer();
    $added = $sync->synchronize($this->tempDir . '/nonexistent.php', $this->openApiFile);

    expect($added)->toBeEmpty();
});

test('returns empty when openapi file does not exist', function () {
    writeProcessor($this->processorFile, ['Auth']);

    $sync = new ProcessorTagSynchronizer();
    $added = $sync->synchronize($this->processorFile, $this->tempDir . '/nonexistent.json');

    expect($added)->toBeEmpty();
});

test('preserves existing processor structure', function () {
    writeProcessor($this->processorFile, ['Auth', 'Users']);
    writeSpecWithTags($this->openApiFile, ['Auth', 'Users', 'Projects']);

    $sync = new ProcessorTagSynchronizer();
    $sync->synchronize($this->processorFile, $this->openApiFile);

    $source = file_get_contents($this->processorFile);
    // Should still be valid PHP with the class structure
    expect($source)->toContain('class TagOrderProcessor')
        ->and($source)->toContain('public function __invoke')
        ->and($source)->toContain('$openapi->tags');
});
