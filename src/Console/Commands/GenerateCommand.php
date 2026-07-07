<?php

namespace Langsys\OpenApiDocsGenerator\Console\Commands;

use Illuminate\Console\Command;
use Langsys\OpenApiDocsGenerator\Generators\ConfigFactory;
use Langsys\OpenApiDocsGenerator\Generators\GeneratorFactory;
use Langsys\OpenApiDocsGenerator\Generators\OpenApiGenerator;
use Langsys\OpenApiDocsGenerator\Generators\ProcessorTagSynchronizer;
use Langsys\OpenApiDocsGenerator\Generators\ThunderClientFactory;

class GenerateCommand extends Command
{
    protected $signature = 'openapi:generate {documentation?} {--all} {--thunder-client} {--refresh} {--wipe}';

    protected $description = 'Generate OpenAPI documentation from annotations and DTOs';

    public function handle(): int
    {
        $failures = 0;

        if ($this->option('all')) {
            $documentations = array_keys(config('openapi-docs.documentations', []));

            foreach ($documentations as $documentation) {
                if (! $this->generateDocumentation($documentation)) {
                    $failures++;
                }
            }
        } else {
            $documentation = $this->argument('documentation') ?? config('openapi-docs.default', 'default');
            if (! $this->generateDocumentation($documentation)) {
                $failures++;
            }
        }

        // A non-zero exit makes strict gates real: a CI/deploy `if artisan openapi:generate`
        // must see failure when any set aborts. Successful sets still wrote their output.
        if ($failures > 0) {
            $this->error(sprintf('%d documentation set(s) failed to generate.', $failures));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return bool  True if the set generated; false if it failed (exception).
     */
    private function generateDocumentation(string $documentation): bool
    {
        $this->info("Generating OpenAPI documentation for '{$documentation}'...");

        try {
            $generator = GeneratorFactory::make($documentation);
            $generator->generateDocs();
            $this->info("Documentation '{$documentation}' generated successfully.");

            $this->reportSelection($documentation, $generator);
            $this->reportUnresolvedReferences($generator);

            $this->synchronizeProcessorTags($documentation);

            if ($this->option('thunder-client')) {
                $generator = ThunderClientFactory::make(
                    $documentation,
                    refresh: (bool) $this->option('refresh'),
                    wipe: (bool) $this->option('wipe'),
                );
                $generator->generate();

                foreach ($generator->getWarnings() as $warning) {
                    $this->warn($warning);
                }

                $this->info('Thunder Client collection updated.');
            }

            return true;
        } catch (\Throwable $e) {
            $this->error("Failed to generate '{$documentation}': {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Print a loud, always-on summary of a filtered set's operation selection.
     */
    private function reportSelection(string $documentation, OpenApiGenerator $generator): void
    {
        $report = $generator->getSelectionReport();

        if ($report === null) {
            return;
        }

        $counts = $report->counts();

        $this->info(sprintf(
            "Filtered set '%s': kept %d, dropped %d operation(s).",
            $documentation,
            $counts['kept'],
            $counts['dropped'],
        ));

        if ($counts['unmatched'] > 0) {
            $this->warn(sprintf(
                '%d operation(s) could not be matched to a route (see policy for disposition):',
                $counts['unmatched'],
            ));

            foreach ($report->unmatched as $entry) {
                $this->warn(sprintf('  - %s %s', strtoupper($entry['method']), $entry['path']));
            }
        }
    }

    /**
     * Warn about referenced-but-undefined $refs when validate_refs is enabled.
     */
    private function reportUnresolvedReferences(OpenApiGenerator $generator): void
    {
        $unresolved = $generator->getUnresolvedReferences();

        if ($unresolved === []) {
            return;
        }

        $this->warn(sprintf('%d unresolved $ref(s) — referenced but never defined:', count($unresolved)));

        foreach ($unresolved as $entry) {
            $this->warn(sprintf('  - %s (used at %s)', $entry['ref'], $entry['location']));
        }
    }

    private function synchronizeProcessorTags(string $documentation): void
    {
        $config = ConfigFactory::documentationConfig($documentation);
        $processors = $config['scan_options']['processors'] ?? [];

        if (empty($processors)) {
            return;
        }

        $openApiFile = ($config['paths']['docs'] ?? storage_path('api-docs'))
            . '/' . ($config['paths']['docs_json'] ?? 'api-docs.json');

        $synchronizer = new ProcessorTagSynchronizer();

        foreach ($processors as $processor) {
            $processorFile = $this->resolveProcessorFile($processor);
            if ($processorFile === null) {
                continue;
            }

            $added = $synchronizer->synchronize($processorFile, $openApiFile);
            if (!empty($added)) {
                $this->info('Added new tags to processor: ' . implode(', ', $added));
            }
        }
    }

    private function resolveProcessorFile(mixed $processor): ?string
    {
        try {
            $class = is_object($processor) ? get_class($processor) : (string) $processor;
            $reflection = new \ReflectionClass($class);
            $file = $reflection->getFileName();
            return $file !== false ? $file : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
