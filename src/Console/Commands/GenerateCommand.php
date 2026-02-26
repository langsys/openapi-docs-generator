<?php

namespace Langsys\OpenApiDocsGenerator\Console\Commands;

use Illuminate\Console\Command;
use Langsys\OpenApiDocsGenerator\Generators\GeneratorFactory;

class GenerateCommand extends Command
{
    protected $signature = 'openapi:generate {documentation?} {--all}';

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
            GeneratorFactory::make($documentation)->generateDocs();
            $this->info("Documentation '{$documentation}' generated successfully.");
        } catch (\Throwable $e) {
            $this->error("Failed to generate '{$documentation}': {$e->getMessage()}");
        }
    }
}
