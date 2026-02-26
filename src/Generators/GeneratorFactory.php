<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use Psr\Log\LoggerInterface;

class GeneratorFactory
{
    /**
     * Build an OpenApiGenerator from the merged config for a documentation set.
     */
    public static function make(string $documentation, ?LoggerInterface $logger = null): OpenApiGenerator
    {
        $config = ConfigFactory::documentationConfig($documentation);

        $dtoConfig = $config['dto'] ?? [];

        $dtoSchemaBuilder = new DtoSchemaBuilder(
            dtoPath: $dtoConfig['path'] ?? app_path('DataObjects'),
            namespace: $dtoConfig['namespace'] ?? 'App\\DataObjects',
            exampleGenerator: new ExampleGenerator(
                fakerAttributeMapper: $dtoConfig['faker_attribute_mapper'] ?? [],
                customFunctions: $dtoConfig['custom_functions'] ?? [],
            ),
            paginationFields: $dtoConfig['pagination_fields'] ?? [],
        );

        return new OpenApiGenerator(
            annotationsDir: $config['paths']['annotations'] ?? [],
            docsFile: ($config['paths']['docs'] ?? storage_path('api-docs')) . '/' . ($config['paths']['docs_json'] ?? 'api-docs.json'),
            yamlDocsFile: ($config['paths']['docs'] ?? storage_path('api-docs')) . '/' . ($config['paths']['docs_yaml'] ?? 'api-docs.yaml'),
            securitySchemesConfig: $config['security_definitions']['security_schemes'] ?? [],
            securityConfig: $config['security_definitions']['security'] ?? [],
            scanOptions: $config['scan_options'] ?? [],
            constants: $config['constants'] ?? [],
            basePath: $config['paths']['base'] ?? null,
            yamlCopy: $config['generate_yaml_copy'] ?? false,
            endpointParametersConfig: $config['endpoint_parameters'] ?? [],
            dtoSchemaBuilder: $dtoSchemaBuilder,
            logger: $logger,
        );
    }
}
