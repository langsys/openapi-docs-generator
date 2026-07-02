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
        if ($this->option('all')) {
            $documentations = array_keys(config('openapi-docs.documentations', []));

            foreach ($documentations as $documentation) {
                $this->generateDocumentation($documentation);
            }
        } else {
            $documentation = $this->argument('documentation') ?? config('openapi-docs.default', 'default');
            $this->generateDocumentation($documentation);
        }

        return self::SUCCESS;
    }

    private function generateDocumentation(string $documentation): void
    {
        $this->info("Generating OpenAPI documentation for '{$documentation}'...");

        try {
            $generator = GeneratorFactory::make($documentation);
            $generator->generateDocs();
            $this->info("Documentation '{$documentation}' generated successfully.");

            $this->reportSelection($documentation, $generator);

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
        } catch (\Throwable $e) {
            $this->error("Failed to generate '{$documentation}': {$e->getMessage()}");
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
