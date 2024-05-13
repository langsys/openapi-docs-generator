<?php

namespace Langsys\SwaggerAutoGenerator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

final class DataObjectMakeCommand extends Command
{
    protected $signature = "langsys:dto {--model=}";
    protected $description = "Create a new Spatie DTO";
    protected $type = 'Data Transfer Object';

    public function handle()
    {
        $modelClass = $this->option('model');
        if (!class_exists($modelClass)) {
            $this->error("Model class {$modelClass} does not exist.");
            return;
        }

        $model = new $modelClass;
        if (!$model instanceof Model) {
            $this->error("Class {$modelClass} must be an instance of Illuminate\\Database\\Eloquent\\Model");
            return;
        }

        $tableName = $model->getTable();
        $columns = DB::select("SHOW COLUMNS FROM {$tableName}");

        $properties = collect($columns)->map(function ($column) {
            $type = $this->resolveType($column->Type);
            $nullable = $column->Null === 'YES' ? '?' : '';
            return "public {$nullable}{$type} \${$column->Field}";
        })->implode(",\n");

        $namespace = $this->getDefaultNamespace($this->laravel->getNamespace());
        $class = class_basename($modelClass) . config('langsys-generator.data_object_suffix');

        $stub = $this->getStub();
        $contents = str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ properties }}'],
            [$namespace, $class, $properties],
            file_get_contents($stub)
        );

        $path = config('langsys-generator.paths.data_objects') . "/{$class}.php";
        if (file_exists($path)) {
            $this->error("{$class} already exists.");
            return;
        }

        (new Filesystem)->ensureDirectoryExists(dirname($path));
        file_put_contents($path, $contents);

        $this->info("{$class} created successfully.");
    }

    protected function resolveType($dbType): string
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

    protected function getDefaultNamespace($rootNamespace): string
    {
        return "{$rootNamespace}\\DataObjects";
    }
}
