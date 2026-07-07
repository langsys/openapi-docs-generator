<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use Illuminate\Routing\Router;
use Langsys\OpenApiDocsGenerator\Filters\OperationFilterFactory;
use Langsys\OpenApiDocsGenerator\Routing\LaravelRouteResolver;
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
        $annotationDirs = $config['paths']['annotations'] ?? [app_path()];

        $dtoSchemaBuilder = new DtoSchemaBuilder(
            dtoPaths: $annotationDirs,
            exampleGenerator: new ExampleGenerator(
                fakerAttributeMapper: $dtoConfig['faker_attribute_mapper'] ?? [],
                customFunctions: $dtoConfig['custom_functions'] ?? [],
            ),
            paginationFields: $dtoConfig['pagination_fields'] ?? [],
        );

        $filterConfig = $config['filter'] ?? [];
        $operationSelector = self::makeOperationSelector($filterConfig, $logger);

        $securityOverride = $config['security_override'] ?? null;
        // Clean docs by default: prune components/tags no operation references.
        // Filtered sets always prune (an unpruned filtered set would carry the
        // dropped operations' schemas); other sets can opt out with `false`.
        $pruneComponents = $operationSelector !== null
            || ($config['prune_unused_components'] ?? true);

        // Opt-in unresolved-$ref detection: false/'off' (default), 'warn', 'strict'.
        $validateRefs = $config['validate_refs'] ?? 'off';
        $validateRefs = match ($validateRefs) {
            true => 'warn',
            false => 'off',
            default => (string) $validateRefs,
        };

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
            operationSelector: $operationSelector,
            securityOverride: $securityOverride,
            pruneComponents: $pruneComponents,
            validateRefs: $validateRefs,
        );
    }

    /**
     * Build the operation selector for a documentation set, or null when the set
     * declares no include/exclude filters.
     *
     * @param  array<string, mixed>  $filterConfig
     */
    private static function makeOperationSelector(array $filterConfig, ?LoggerInterface $logger): ?OperationSelector
    {
        $include = $filterConfig['include'] ?? [];
        $exclude = $filterConfig['exclude'] ?? [];

        if (empty($include) && empty($exclude)) {
            return null;
        }

        /** @var Router $router */
        $router = app('router');
        $aliasMap = method_exists($router, 'getMiddleware') ? $router->getMiddleware() : [];

        $resolver = new LaravelRouteResolver(
            router: $router,
            basePrefix: $filterConfig['route_prefix'] ?? null,
        );

        $filterFactory = new OperationFilterFactory($aliasMap);

        return new OperationSelector(
            routeResolver: $resolver,
            include: $filterFactory->makeMany($include),
            exclude: $filterFactory->makeMany($exclude),
            unmatched: $filterConfig['unmatched'] ?? 'exclude',
            logger: $logger,
        );
    }
}
