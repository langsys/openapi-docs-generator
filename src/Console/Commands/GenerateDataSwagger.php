<?php

namespace Langsys\SwaggerAutoGenerator\Console\Commands;

use Langsys\SwaggerAutoGenerator\Generators\Swagger\SwaggerSchemaGenerator;
use Illuminate\Console\Command;

class GenerateDataSwagger extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'langsys-swagger:generate {--cascade} {--docs} {--pretty} {--ts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate Swagger schemas from data objects';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): void
    {
        $cascade = $this->option('cascade');
        $docs = $this->option('docs');
        $pretty = $this->option('pretty');
        $ts = $this->option('ts');
        $generator = new SwaggerSchemaGenerator();
        $generatedSchemas = $generator->swaggerAnnotationsFromDataObjects($cascade, $pretty);
        $this->info("Generated $generatedSchemas schemas");

        if ($docs) {
            $this->call('l5-swagger:generate');
        }
        if ($ts) {
            $this->call('typescript:transform');
        }
    }
}
