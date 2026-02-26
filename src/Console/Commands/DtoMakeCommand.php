<?php

namespace Langsys\OpenApiDocsGenerator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DtoMakeCommand extends Command
{
    protected $signature = 'openapi:dto {--model=}';

    protected $description = 'Create a new Spatie DTO from an Eloquent model';

    protected $type = 'Data Transfer Object';

    public function handle(): int
    {
        $modelClass = $this->option('model');

        if (! $modelClass) {
            $this->error('The --model option is required.');

            return self::FAILURE;
        }

        if (! class_exists($modelClass)) {
            $this->error("Model class {$modelClass} does not exist.");

            return self::FAILURE;
        }

        $model = new $modelClass;

        if (! $model instanceof Model) {
            $this->error("Class {$modelClass} must be an instance of Illuminate\\Database\\Eloquent\\Model");

            return self::FAILURE;
        }

        $tableName = $model->getTable();
        $columns = DB::select("SHOW COLUMNS FROM {$tableName}");

        $properties = collect($columns)->map(function ($column) {
            $type = $this->resolveType($column->Type);
            $nullable = $column->Null === 'YES' ? '?' : '';

            return "public {$nullable}{$type} \${$column->Field}";
        })->implode(",\n");

        $namespace = config('openapi-docs.defaults.dto.namespace', 'App\\DataObjects');
        $class = class_basename($modelClass) . 'Data';

        $stub = $this->getStub();
        $contents = str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ properties }}'],
            [$namespace, $class, $properties],
            file_get_contents($stub)
        );

        $path = config('openapi-docs.defaults.dto.path', app_path('DataObjects')) . "/{$class}.php";

        if (file_exists($path)) {
            $this->error("{$class} already exists.");

            return self::FAILURE;
        }

        (new Filesystem)->ensureDirectoryExists(dirname($path));
        file_put_contents($path, $contents);

        $this->info("{$class} created successfully.");

        return self::SUCCESS;
    }

    protected function resolveType(string $dbType): string
    {
        return match (true) {
            Str::startsWith($dbType, ['int', 'tinyint', 'smallint', 'mediumint', 'bigint']) => 'int',
            Str::startsWith($dbType, ['float', 'double', 'decimal', 'real']) => 'float',
            Str::startsWith($dbType, 'bool') => 'bool',
            default => 'string',
        };
    }

    protected function getStub(): string
    {
        $readonly = Str::contains(
            haystack: PHP_VERSION,
            needles: '8.2',
        );

        $file = $readonly ? 'dto-82.stub' : 'dto.stub';

        return __DIR__ . "/../../../stubs/{$file}";
    }
}
