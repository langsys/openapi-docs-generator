<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

class ThunderClientFactory
{
    public static function make(string $documentation, bool $refresh = false, bool $wipe = false): ThunderClientGenerator
    {
        $config = ConfigFactory::documentationConfig($documentation);
        $tc = $config['thunder_client'] ?? [];

        $outputDir = $tc['output_dir'] ?? base_path('thunder-tests');
        $slug = $tc['collection_slug'] ?? 'api';
        $collectionFile = $outputDir . '/collections/tc_col_' . $slug . '.json';

        $docsDir = $config['paths']['docs'] ?? storage_path('api-docs');
        $docsJson = $config['paths']['docs_json'] ?? 'api-docs.json';
        $openApiFile = $docsDir . '/' . $docsJson;

        $envConfig = $tc['environment'] ?? null;
        $envFile = null;
        if ($envConfig) {
            $envSlug = $envConfig['slug'] ?? 'local';
            $envFile = $outputDir . '/tc_env_' . $envSlug . '.json';
        }

        return new ThunderClientGenerator(
            openApiFile: $openApiFile,
            collectionFile: $collectionFile,
            collectionName: $tc['collection_name'] ?? null,
            authSchemes: $tc['auth'] ?? [],
            defaultAuth: $tc['default_auth'] ?? 'none',
            baseUrlVariable: $tc['base_url_variable'] ?? 'url',
            skipPathSegments: $tc['skip_path_segments'] ?? ['api', 'v1', 'v2', 'v3'],
            defaultHeaders: $tc['default_headers'] ?? [['name' => 'Accept', 'value' => 'application/json']],
            environmentConfig: $envConfig,
            environmentFile: $envFile,
            refresh: $refresh,
            wipe: $wipe,
        );
    }
}
