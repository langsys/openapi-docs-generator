<?php

namespace Langsys\SwaggerAutoGenerator\Generators\Swagger;

use Exception;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Finder\Finder;

class SwaggerSchemaGenerator
{
    private Finder $finder;
    private string $_destinationFile;

    public function __construct()
    {
        $this->finder = new Finder();
        $this->exampleGenerator = new ExampleGenerator();
        //$this->_destinationFile = base_path('app/Swagger') . '/Schemas.php';
        $this->_destinationFile = config('langsys-generator.paths.swagger_docs');
    }

    public function swaggerAnnotationsFromDataObjects(bool $cascade = false, bool $prettify = true): int
    {
        $dataObjects = $this->_getDataObjects();
        $generatedSchemas = 0;

        $fileExists = file_exists($this->_destinationFile);

        if($fileExists) {
            $previousContent = file_get_contents($this->_destinationFile);
        }

        try {
            //Create the file and folder if they don't exist, otherwise clear the file
            if ($fileExists &&
                !file_exists(dirname($this->_destinationFile) &&
                !mkdir($concurrentDirectory = dirname($this->_destinationFile), 0777, true) &&
                !is_dir($concurrentDirectory))
            ) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));

            }
            //Delete the file content
            file_put_contents($this->_destinationFile, '');

            file_put_contents($this->_destinationFile, '<?php namespace App\Swagger;' . PHP_EOL . '/**  ' . PHP_EOL);

            foreach ($dataObjects as $dataObject) {
                if (!$schema = $this->generateSchema($dataObject, $prettify)) {
                    continue;
                }
                $schemas = [$schema];

                if ($schema->isResource()) {
                    $schemas = [$schema, ...$this->_generateResponseSchemas($schema)];
                }

                foreach ($schemas as $schema) {
                    $saved = file_put_contents(
                            $this->_destinationFile,
                            $schema->toSwagger(cascade: $cascade),
                            FILE_APPEND
                        ) !== false;
                    $generatedSchemas += (int)$saved;
                }
            }
            file_put_contents($this->_destinationFile, ' */ ' . PHP_EOL . ' class Schemas {}', FILE_APPEND);

        } catch(Exception $e) {
            if($fileExists) {
                file_put_contents($this->_destinationFile, '');
                file_put_contents($this->_destinationFile, $previousContent);
            }
            throw $e;
        }


        return $generatedSchemas;
    }

    public function generateSchema(string $className, bool $prettify = true): Schema|bool
    {
        $schema = new Schema($className, $prettify);
        return !$schema->isEmpty() ? $schema : false;
    }

    private function _dataIsRequest(string $className): bool
    {
        return str_contains($className, 'Request');
    }

    private function _getDataObjects()
    {
        $dataObjects = [];

        foreach ($this->finder->files()->in(app_path()) as $file) {
            $relativePath = str_replace('.php', '', $file->getRelativePathname());
            $className = 'App\\' . str_replace('/', '\\', $relativePath);

            if ($this->_isDataObject($className)) {
                $dataObjects[] = $className;
            }
        }
        return $dataObjects;
    }

    private function _isDataObject(string $className)
    {
        try {
            $reflectionClass = new ReflectionClass($className);
            $parentClass = $reflectionClass->getParentClass();


            if (!$parentClass) {
                return false;
            }


            return $parentClass->getName() === 'Spatie\\LaravelData\\Data' ||
                $this->_isDataObject($parentClass->getName());
        } catch (Exception $exception) {
            return false;
        }
    }

    private function _generateResponseSchemas(Schema $schema): array
    {
        $responseSchema = $this->_generateResponseSchema($schema);
        $paginatedResponseSchema = $this->_generatePaginatedResponseSchema($schema);
        $listResponseSchemas = $this->_generateListResponseSchema($schema);
        return [$responseSchema, $paginatedResponseSchema, $listResponseSchemas];
    }

    private function _generateResponseSchema(Schema $schema): Schema
    {
        $responseSchema = new Schema("{$schema->name}Response", $schema->prettify, false);

        $responseSchema->addProperty(
            new Property(
                'status',
                'Response status',
                true,
                'bool'
            )
        );

        $responseSchema->addProperty(
            new Property(
                'data',
                'Response payload',
                $schema,
                'object'
            )
        );

        return $responseSchema;
    }

    private function _generatePaginatedResponseSchema(Schema $schema): Schema
    {
        $paginatedResponseSchema = new Schema("{$schema->name}PaginatedResponse", $schema->prettify, false);

        foreach (config('langsys-generator.pagination_fields') as $field) {
            $paginatedResponseSchema->addProperty(
                new Property(
                    $field['name'],
                    $field['description'],
                    $field['content'],
                    $field['type']
                )
            );
        }

        $paginatedResponseSchema->addProperty(
            new Property(
                'data',
                'List of items',
                $schema,
                'array'
            )
        );


        return $paginatedResponseSchema;
    }
    private function _generateListResponseSchema(Schema $schema): Schema
    {
        $paginatedResponseSchema = new Schema("{$schema->name}ListResponse", $schema->prettify, false);

        $paginatedResponseSchema->addProperty(
            new Property(
                'status',
                'Response status',
                true,
                'bool'
            )
        );
        $paginatedResponseSchema->addProperty(
            new Property(
                'data',
                'List of items',
                $schema,
                'array'
            )
        );


        return $paginatedResponseSchema;
    }
}
