<?php

namespace Langsys\OpenApiDocsGenerator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class MakeProcessorCommand extends Command
{
    protected $signature = 'openapi:make-processor {name=TagOrderProcessor} {--namespace=App\\Swagger}';

    protected $description = 'Generate a swagger-php processor stub for controlling tag order in Swagger UI';

    public function handle(): int
    {
        $name = $this->argument('name');
        $namespace = $this->option('namespace');

        $relativePath = str_replace('\\', '/', str_replace('App\\', 'app/', $namespace));
        $path = base_path($relativePath) . '/' . $name . '.php';

        if (file_exists($path)) {
            $this->error("{$name} already exists at {$path}");
            return self::FAILURE;
        }

        $stub = file_get_contents(__DIR__ . '/../../../stubs/tag-order-processor.stub');
        $contents = str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$namespace, $name],
            $stub
        );

        (new Filesystem)->ensureDirectoryExists(dirname($path));
        file_put_contents($path, $contents);

        $this->info("Processor created: {$path}");
        $this->newLine();
        $this->line("Add it to your <comment>config/openapi-docs.php</comment>:");
        $this->newLine();
        $this->line("  'scan_options' => [");
        $this->line("      'processors' => [new \\{$namespace}\\{$name}()],");
        $this->line("  ],");

        return self::SUCCESS;
    }
}
