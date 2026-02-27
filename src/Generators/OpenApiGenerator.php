<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use OpenApi\Processors\BuildPaths;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

class OpenApiGenerator
{
    public const OPEN_API_DEFAULT_SPEC_VERSION = '3.0.0';

    private OA\OpenApi $openApi;

    public function __construct(
        private array $annotationsDir,
        private string $docsFile,
        private string $yamlDocsFile,
        private array $securitySchemesConfig,
        private array $securityConfig,
        private array $scanOptions,
        private array $constants,
        private ?string $basePath,
        private bool $yamlCopy,
        private array $endpointParametersConfig,
        private DtoSchemaBuilder $dtoSchemaBuilder,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Run the full generation pipeline.
     *
     * @throws OpenApiDocsException
     */
    public function generateDocs(): void
    {
        $this->prepareDirectory();
        $this->defineConstants();
        $this->scanFilesForDocumentation();
        $this->buildAndMergeDtoSchemas();
        $this->populateServers();
        $this->enrichEndpointParameters();
        $this->saveJson();
        $this->injectSecurity();
        $this->makeYamlCopy();
    }

    /**
     * Ensure the output directory exists and is writable.
     *
     * @throws OpenApiDocsException
     */
    private function prepareDirectory(): void
    {
        $directory = dirname($this->docsFile);

        if (! is_dir($directory)) {
            if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                throw new OpenApiDocsException(
                    "Unable to create output directory: {$directory}"
                );
            }
        }

        if (! is_writable($directory)) {
            throw new OpenApiDocsException(
                "Output directory is not writable: {$directory}"
            );
        }
    }

    /**
     * Define PHP constants from config for use in annotations.
     */
    private function defineConstants(): void
    {
        foreach ($this->constants as $key => $value) {
            defined($key) || define($key, $value);
        }
    }

    /**
     * Scan annotation directories using zircote/swagger-php to build the OpenAPI model.
     */
    private function scanFilesForDocumentation(): void
    {
        $dirs = array_filter($this->annotationsDir, 'is_dir');

        if (empty($dirs)) {
            throw new OpenApiDocsException(
                'No valid annotation directories to scan. Check paths.annotations in your openapi-docs config.'
            );
        }

        $generator = new Generator($this->logger);

        // Set OpenAPI spec version
        $version = $this->scanOptions['open_api_spec_version'] ?? self::OPEN_API_DEFAULT_SPEC_VERSION;
        if (method_exists($generator, 'setVersion')) {
            $generator->setVersion($version);
        }

        // Configure processors: inject custom ones after BuildPaths
        $this->configureProcessors($generator);

        // Set processor configuration
        $defaultProcessorsConfig = $this->scanOptions['default_processors_configuration'] ?? [];
        if (! empty($defaultProcessorsConfig) && method_exists($generator, 'setConfig')) {
            $generator->setConfig($defaultProcessorsConfig);
        }

        // Set custom analyser if configured
        $analyser = $this->scanOptions['analyser'] ?? null;
        if ($analyser !== null) {
            $generator->setAnalyser($analyser);
        }

        // Build the file finder from annotation directories
        $exclude = $this->scanOptions['exclude'] ?? [];
        $pattern = $this->scanOptions['pattern'] ?? null;
        $finder = \OpenApi\Util::finder($dirs, $exclude, $pattern);

        // Generate the OpenAPI model (skip validation — DTO schemas haven't been merged yet)
        $analysis = $this->scanOptions['analysis'] ?? null;
        $this->openApi = $generator->generate($finder, $analysis, validate: false);
    }

    /**
     * Configure processors, injecting custom ones after BuildPaths.
     */
    private function configureProcessors(Generator $generator): void
    {
        $customProcessors = $this->scanOptions['processors'] ?? [];

        if (empty($customProcessors)) {
            return;
        }

        $defaultProcessors = $generator->getProcessors();
        $processors = [];

        foreach ($defaultProcessors as $processor) {
            $processors[] = $processor;

            // Insert custom processors after BuildPaths
            if ($processor instanceof BuildPaths) {
                foreach ($customProcessors as $customProcessor) {
                    if (is_string($customProcessor)) {
                        $customProcessor = new $customProcessor();
                    }
                    $processors[] = $customProcessor;
                }
            }
        }

        $generator->setProcessors($processors);
    }

    /**
     * Build DTO schemas and merge them into the OpenAPI model.
     *
     * Hand-written (annotation-defined) schemas take precedence.
     * DTO-generated schemas are only added if no schema with the same name exists.
     */
    private function buildAndMergeDtoSchemas(): void
    {
        $dtoSchemas = $this->dtoSchemaBuilder->buildAll();

        if (empty($dtoSchemas)) {
            return;
        }

        // Initialize components if undefined
        if ($this->openApi->components === Generator::UNDEFINED) {
            $this->openApi->components = new OA\Components([]);
        }

        // Initialize schemas array if undefined
        if ($this->openApi->components->schemas === Generator::UNDEFINED) {
            $this->openApi->components->schemas = [];
        }

        foreach ($dtoSchemas as $schema) {
            if (! $this->schemaExists($schema->schema)) {
                $this->openApi->components->schemas[] = $schema;
            }
        }
    }

    /**
     * Check if a schema with the given name already exists in the OpenAPI model.
     */
    private function schemaExists(string $schemaName): bool
    {
        if ($this->openApi->components === Generator::UNDEFINED) {
            return false;
        }

        if ($this->openApi->components->schemas === Generator::UNDEFINED) {
            return false;
        }

        foreach ($this->openApi->components->schemas as $existing) {
            if ($existing->schema === $schemaName) {
                return true;
            }
        }

        return false;
    }

    /**
     * If a base path is configured, add it as a Server entry.
     */
    private function populateServers(): void
    {
        if ($this->basePath === null || $this->basePath === '') {
            return;
        }

        $server = new OA\Server(['url' => $this->basePath]);

        if ($this->openApi->servers === Generator::UNDEFINED) {
            $this->openApi->servers = [];
        }

        $this->openApi->servers[] = $server;
    }

    /**
     * Enrich endpoint parameters (order_by/filter_by) if enabled and resolver is configured.
     */
    private function enrichEndpointParameters(): void
    {
        if (empty($this->endpointParametersConfig['enabled'])) {
            return;
        }

        $resolverClass = $this->endpointParametersConfig['resolver'] ?? null;

        if ($resolverClass === null) {
            return;
        }

        $resolver = new $resolverClass();

        $enricher = new EndpointParameterEnricher(
            resolver: $resolver,
            parameterNames: $this->endpointParametersConfig['parameters'] ?? ['order_by', 'filter_by'],
            includeExtensions: $this->endpointParametersConfig['include_extensions'] ?? true,
            globalOrderableFields: $this->endpointParametersConfig['global_orderable_fields'] ?? [],
        );

        $enricher->enrich($this->openApi);
    }

    /**
     * Save the OpenAPI model as a JSON file.
     */
    private function saveJson(): void
    {
        $this->openApi->saveAs($this->docsFile);
    }

    /**
     * Inject security definitions from config into the generated JSON.
     */
    private function injectSecurity(): void
    {
        if (empty($this->securitySchemesConfig) && empty($this->securityConfig)) {
            return;
        }

        $security = new SecurityDefinitions(
            securitySchemesConfig: $this->securitySchemesConfig,
            securityConfig: $this->securityConfig,
        );

        $security->generate($this->docsFile);
    }

    /**
     * If YAML copy is enabled, convert the JSON output to YAML.
     */
    private function makeYamlCopy(): void
    {
        if (! $this->yamlCopy) {
            return;
        }

        $jsonContent = file_get_contents($this->docsFile);
        $data = json_decode($jsonContent, true);

        $yaml = Yaml::dump(
            $data,
            20,
            2,
            Yaml::DUMP_OBJECT_AS_MAP ^ Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE
        );

        file_put_contents($this->yamlDocsFile, $yaml);
    }
}
