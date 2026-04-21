<?php

namespace Langsys\OpenApiDocsGenerator\Console\Commands;

use Illuminate\Console\Command;
use Langsys\OpenApiDocsGenerator\Generators\ThunderClientFactory;

class ThunderClientCommand extends Command
{
    protected $signature = 'openapi:thunder {documentation?} {--refresh} {--wipe}';

    protected $description = 'Generate Thunder Client collection from OpenAPI documentation';

    public function handle(): int
    {
        $documentation = $this->argument('documentation') ?? config('openapi-docs.default', 'default');
        $refresh = (bool) $this->option('refresh');
        $wipe = (bool) $this->option('wipe');

        $this->info("Generating Thunder Client collection for '{$documentation}'...");

        try {
            $generator = ThunderClientFactory::make($documentation, refresh: $refresh, wipe: $wipe);
            $generator->generate();

            foreach ($generator->getWarnings() as $warning) {
                $this->warn($warning);
            }

            $this->info('Thunder Client collection generated successfully.');
        } catch (\Throwable $e) {
            $this->error("Failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
