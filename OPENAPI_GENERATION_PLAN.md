# Version 2.0 — `langsys/openapi-docs-generator`

## Overview

Generation-only package. Produces `api-docs.json` / `api-docs.yaml` from Spatie Data DTOs + controller annotations. No UI — bring your own viewer (Swagger UI, Redocly, Scalar, Postman, etc.).

### The Paradigm Shift

**v1 (`langsys/swagger-auto-generator`):** Two-step process with an intermediate text artifact.
```
DTOs → @OA\Schema annotation text (Schemas.php) → l5-swagger scans all annotations → api-docs.json
```

**v2 (`langsys/openapi-docs-generator`):** Single unified pipeline. No intermediate files. No UI.
```
openapi:generate
  ├── Scan controller annotations (routes, operations, parameters) via zircote/swagger-php
  ├── Reflect on Spatie Data DTOs → build OpenApi\Schema objects programmatically
  ├── Merge DTO schemas into the OpenAPI object model
  ├── Enrich endpoint parameters (order_by/filter_by)
  ├── Inject security definitions from config
  └── Write api-docs.json / api-docs.yaml
```

**What this eliminates:**
- The `Schemas.php` output file — no more generated annotation text
- `SwaggerSchemaGenerator`, `Schema`, `Property` classes (text-based annotation generation)
- `PrintsSwagger` interface, `PrettyPrints` trait (text formatting for annotations)
- The `data-swagger:generate` command as a separate schema step
- The `--cascade`, `--minified` flags (were about formatting annotation text)
- The dependency on `darkaonline/l5-swagger`
- Any UI serving — no routes, controllers, views, or asset serving

**What this keeps:**
- `Attributes/` (Example, Description, Omit, GroupedCollection) — still read during reflection
- `ExampleGenerator` — still produces example values for schema properties
- `CustomFunctions` — used by ExampleGenerator
- DTO scaffolding command (`openapi:dto`) — scaffolds DTOs from Eloquent models (orthogonal)

**What this adds:**
- `DtoSchemaBuilder` — reflects on DTOs and builds `OpenApi\Annotations\Schema` objects directly
- Security definitions from config
- Multiple documentation sets
- Per-endpoint parameter enrichment (order_by/filter_by)

---

## Table of Contents

1. [Dependency Changes](#1-dependency-changes)
2. [Architecture Overview](#2-architecture-overview)
3. [Configuration Structure](#3-configuration-structure)
4. [DTO Schema Building](#4-dto-schema-building)
5. [Core Document Generation](#5-core-document-generation)
6. [Security Definitions](#6-security-definitions)
7. [Multiple Documentation Sets](#7-multiple-documentation-sets)
8. [Endpoint Parameter Enrichment](#8-endpoint-parameter-enrichment)
9. [Artisan Commands](#9-artisan-commands)
10. [Service Provider & Facade](#10-service-provider--facade)
11. [Implementation Steps](#11-implementation-steps)
12. [Migration Guide for Consuming Projects](#12-migration-guide-for-consuming-projects)
13. [Testing Strategy](#13-testing-strategy)
14. [File Inventory](#14-file-inventory)

---

## 1. Dependency Changes

### composer.json

```json
{
  "name": "langsys/openapi-docs-generator",
  "description": "OpenAPI documentation generator for Laravel with Spatie Data DTO support",
  "require": {
    "php": "^8.1",
    "zircote/swagger-php": "^4.0",
    "symfony/yaml": "^5.0 || ^6.0 || ^7.0",
    "spatie/laravel-data": "^3.9 || ^4.0"
  },
  "extra": {
    "laravel": {
      "providers": ["Langsys\\OpenApiDocsGenerator\\OpenApiDocsServiceProvider"],
      "aliases": {
        "OpenApiDocs": "Langsys\\OpenApiDocsGenerator\\OpenApiDocsFacade"
      }
    }
  }
}
```

**Dependencies:**
- `zircote/swagger-php` ^4.0 — annotation parsing engine (for controller annotations)
- `symfony/yaml` — YAML output generation
- `spatie/laravel-data` ^3.9 || ^4.0 — DTO framework

No `swagger-api/swagger-ui` — UI is the consumer's concern.

---

## 2. Architecture Overview

### Naming Convention

| Concept | Name |
|---------|------|
| Composer package | `langsys/openapi-docs-generator` |
| PHP namespace | `Langsys\OpenApiDocsGenerator` |
| Config key | `openapi-docs` |
| Env variable prefix | `OPENAPI_` |
| Artisan commands | `openapi:generate`, `openapi:dto` |

### Directory Structure

```
src/
├── OpenApiDocsServiceProvider.php             # Service provider
├── OpenApiDocsFacade.php                      # Facade
├── config/
│   └── openapi-docs.php                       # Configuration
├── Console/Commands/
│   ├── GenerateCommand.php                    # openapi:generate
│   └── DtoMakeCommand.php                     # openapi:dto (scaffolds DTOs from models)
├── Functions/
│   └── CustomFunctions.php                    # Custom example generators
├── Generators/
│   ├── OpenApiGenerator.php                   # Main orchestrator
│   ├── DtoSchemaBuilder.php                   # Reflects on DTOs → builds OA\Schema objects
│   ├── GeneratorFactory.php                   # Creates OpenApiGenerator from config
│   ├── ConfigFactory.php                      # Deep-merges defaults + per-documentation config
│   ├── SecurityDefinitions.php                # Injects security schemes from config
│   ├── ExampleGenerator.php                   # Produces example values via Faker
│   ├── EndpointParameterEnricher.php          # Enriches order_by/filter_by per endpoint
│   └── Attributes/                            # Read during DTO reflection
│       ├── OpenApiAttribute.php               # Base attribute class
│       ├── Example.php
│       ├── Description.php
│       ├── Omit.php
│       └── GroupedCollection.php
├── Contracts/
│   └── EndpointParameterResolver.php          # Interface for parameter data sources
├── Resolvers/
│   └── DatabaseEndpointParameterResolver.php  # Default resolver (api_resources tables)
├── Data/
│   └── EndpointParameterData.php              # DTO for resolved parameter data
└── Exceptions/
    └── OpenApiDocsException.php               # Package exception
```

### Pipeline

```
openapi:generate command
  └── OpenApiGenerator.generateDocs()
        │
        ├── 1. Define PHP constants (for use in annotations)
        │
        ├── 2. Scan controller annotations (zircote/swagger-php)
        │      → Parses @OA\Get, @OA\Post, @OA\Parameter, @OA\Response, etc.
        │      → Produces OpenApi\Annotations\OpenApi object
        │
        ├── 3. Build DTO schemas (DtoSchemaBuilder)
        │      → Scans configured directory for Spatie Data classes
        │      → For each DTO: reflects on properties, reads attributes
        │      → Builds OpenApi\Annotations\Schema objects programmatically
        │      → For Resources: auto-generates Response/PaginatedResponse/ListResponse
        │      → Merges all schemas into openapi.components.schemas
        │
        ├── 4. Populate servers (base path config)
        │
        ├── 5. Enrich endpoint parameters (if enabled)
        │
        ├── 6. Write JSON → api-docs.json
        │
        ├── 7. Inject security definitions from config
        │
        └── 8. Write YAML copy (if enabled)
```

---

## 3. Configuration Structure

```php
// config/openapi-docs.php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Documentation
    |--------------------------------------------------------------------------
    */
    'default' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Documentation Sets
    |--------------------------------------------------------------------------
    | Each key defines a documentation set that can override any value from
    | the 'defaults' section. Useful for API versioning (v1, v2) or
    | separating public/internal APIs.
    */
    'documentations' => [
        'default' => [
            'paths' => [
                'docs_json' => 'api-docs.json',
                'docs_yaml' => 'api-docs.yaml',
                'annotations' => [],  // e.g. [app_path()] — controller annotation scan paths
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults (shared across all documentation sets)
    |--------------------------------------------------------------------------
    | Every documentation set inherits these. Per-documentation config
    | overrides via deep merge (associative arrays merged, scalars/indexed
    | arrays replaced).
    */
    'defaults' => [

        // --- DTO Schema Generation ---
        'dto' => [
            'path' => app_path('DataObjects'),
            'namespace' => 'App\\DataObjects',

            'faker_attribute_mapper' => [
                'address_1' => 'streetAddress',
                'address_2' => 'buildingNumber',
                'zip' => 'postcode',
                '_at' => 'date',
                '_url' => 'url',
                'locale' => 'locale',
                'phone' => 'phoneNumber',
                '_id' => 'id',
            ],

            'custom_functions' => [
                'id' => [\Langsys\OpenApiDocsGenerator\Functions\CustomFunctions::class, 'id'],
                'date' => [\Langsys\OpenApiDocsGenerator\Functions\CustomFunctions::class, 'date'],
            ],

            'pagination_fields' => [
                ['name' => 'status', 'description' => 'Response status', 'content' => true, 'type' => 'bool'],
                ['name' => 'page', 'description' => 'Current page number', 'content' => 1, 'type' => 'int'],
                ['name' => 'records_per_page', 'description' => 'Records per page', 'content' => 8, 'type' => 'int'],
                ['name' => 'page_count', 'description' => 'Number of pages', 'content' => 5, 'type' => 'int'],
                ['name' => 'total_records', 'description' => 'Total items', 'content' => 40, 'type' => 'int'],
            ],
        ],

        // --- Output Paths ---
        'paths' => [
            'docs' => storage_path('api-docs'),
            'base' => env('OPENAPI_BASE_PATH', null),
            'excludes' => [],
        ],

        // --- Scan Options (for controller annotation scanning) ---
        'scan_options' => [
            'default_processors_configuration' => [],
            'analyser' => null,
            'analysis' => null,
            'processors' => [],
            'pattern' => null,
            'exclude' => [],
            'open_api_spec_version' => env('OPENAPI_SPEC_VERSION', '3.0.0'),
        ],

        // --- Security Definitions ---
        'security_definitions' => [
            'security_schemes' => [
                /*
                 * Examples:
                 *
                 * 'sanctum' => [
                 *     'type' => 'apiKey',
                 *     'description' => 'Enter token in format: Bearer <token>',
                 *     'name' => 'Authorization',
                 *     'in' => 'header',
                 * ],
                 *
                 * 'passport' => [
                 *     'type' => 'oauth2',
                 *     'flows' => [
                 *         'password' => [
                 *             'authorizationUrl' => '/oauth/authorize',
                 *             'tokenUrl' => '/oauth/token',
                 *             'refreshUrl' => '/oauth/token/refresh',
                 *             'scopes' => [],
                 *         ],
                 *     ],
                 * ],
                 */
            ],
            'security' => [
                /*
                 * Examples:
                 * ['sanctum' => []],
                 */
            ],
        ],

        // --- Generation Behavior ---
        'generate_yaml_copy' => env('OPENAPI_GENERATE_YAML', false),

        // --- Constants (defined as PHP constants for use in annotations) ---
        'constants' => [
            // e.g. 'API_HOST' => env('API_HOST', 'http://localhost'),
        ],

        // --- Endpoint Parameter Enrichment (unique to this package) ---
        'endpoint_parameters' => [
            'enabled' => false,
            'resolver' => null,
            'parameters' => ['order_by', 'filter_by'],
            'include_extensions' => true,
            'global_orderable_fields' => ['created_at', 'updated_at'],
        ],
    ],
];
```

---

## 4. DTO Schema Building

The **core paradigm change** — replacing text annotation generation with direct object construction.

### `DtoSchemaBuilder`

Reflects on Spatie `Data` classes and builds `OpenApi\Annotations\Schema` objects.

```php
namespace Langsys\OpenApiDocsGenerator\Generators;

use OpenApi\Annotations as OA;

class DtoSchemaBuilder
{
    public function __construct(
        private string $dtoPath,
        private string $namespace,
        private ExampleGenerator $exampleGenerator,
        private array $paginationFields,
    ) {}

    /**
     * Scan the DTO directory and build all Schema objects.
     *
     * @return OA\Schema[]
     */
    public function buildAll(): array
    {
        $schemas = [];

        foreach ($this->discoverDtoClasses() as $className) {
            $schema = $this->buildSchema($className);
            if ($schema) {
                $schemas[] = $schema;

                if ($this->isResource($className)) {
                    $schemas = array_merge($schemas, $this->buildResponseSchemas($className, $schema));
                }
            }
        }

        return $schemas;
    }

    /**
     * Build a single OA\Schema from a DTO class via reflection.
     */
    public function buildSchema(string $className): ?OA\Schema
    {
        $reflection = new \ReflectionClass($className);
        $properties = [];
        $required = [];

        foreach ($this->getClassProperties($reflection) as $prop) {
            $meta = $this->extractPropertyMetadata($prop);
            if ($meta->omit) continue;

            $oaProperty = $this->buildProperty($meta);
            $properties[] = $oaProperty;

            if ($meta->required) {
                $required[] = $meta->name;
            }
        }

        if (empty($properties)) return null;

        return new OA\Schema([
            'schema' => $reflection->getShortName(),
            'properties' => $properties,
            'required' => $required ?: OA\UNDEFINED,
        ]);
    }
}
```

### How Property Reflection Works

For each public property / constructor parameter on a DTO class:

1. **Read PHP type** — `string`, `int`, `bool`, `float`, `array`, nullable (`?string`), union types
2. **Read attributes** — `#[Example]`, `#[Description]`, `#[Omit]`, `#[GroupedCollection]`, `#[DataCollectionOf]`
3. **Detect special types:**
   - **Enum** → `type: string` (or `integer`), `enum: [case1, case2, ...]`
   - **Nested Data class** → `$ref: #/components/schemas/NestedClassName`
   - **Collection of Data** → `type: array`, `items: { $ref: ... }`
   - **GroupedCollection** → `type: object`, `additionalProperties: { type: array, items: { $ref: ... } }`
4. **Generate example** — Use `ExampleGenerator` (Faker-based), respecting `#[Example]` attribute if present
5. **Build `OA\Property`** object directly

### Example: v1 vs v2

**v1 produced text** (written to `Schemas.php`):
```php
/**
 * @OA\Schema(schema="TestData",
 *     @OA\Property(property="id", type="integer", example=468),
 *     @OA\Property(property="name", type="string", example="John"),
 * )
 */
```

**v2 produces objects** (in memory, merged into OpenApi model):
```php
new OA\Schema([
    'schema' => 'TestData',
    'properties' => [
        new OA\Property(['property' => 'id', 'type' => 'integer', 'example' => 468]),
        new OA\Property(['property' => 'name', 'type' => 'string', 'example' => 'John']),
    ],
])
```

### Auto-Generated Response Schemas

For any DTO whose class name ends with `Resource`, three additional schemas:

- **`{Name}Response`** — `{ status: bool, data: {Name}Resource }`
- **`{Name}PaginatedResponse`** — `{ status: bool, page: int, ..., data: [{Name}Resource] }`
- **`{Name}ListResponse`** — `{ status: bool, data: [{Name}Resource] }`

Uses `pagination_fields` config for PaginatedResponse wrapper fields.

### Merging Into the OpenAPI Object

```php
$openapi = $this->scanControllerAnnotations();   // Routes, operations, manual schemas
$dtoSchemas = $this->dtoSchemaBuilder->buildAll(); // DTO-derived schemas

// Additive merge — hand-written schemas take precedence
foreach ($dtoSchemas as $schema) {
    if (!$this->schemaExists($openapi, $schema->schema)) {
        $openapi->components->schemas[] = $schema;
    }
}
```

---

## 5. Core Document Generation

### `OpenApiGenerator`

```php
namespace Langsys\OpenApiDocsGenerator\Generators;

class OpenApiGenerator
{
    public const OPEN_API_DEFAULT_SPEC_VERSION = '3.0.0';

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
    ) {}

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
}
```

**Pipeline steps:**

1. **`prepareDirectory()`** — Ensure output directory exists and is writable. Throw `OpenApiDocsException` on failure.

2. **`defineConstants()`** — Iterate `constants` config, `define()` each (guarded with `defined()`).

3. **`scanFilesForDocumentation()`** — Create `\OpenApi\Generator`, configure with processors (injected after `BuildPaths`), processor config, OpenAPI version, custom analyser, file finder with `annotationsDir`, `exclude`, `pattern`. Call `$generator->generate()` → `$this->openApi`.

4. **`buildAndMergeDtoSchemas()`** — Call `DtoSchemaBuilder::buildAll()`, merge into `$this->openApi->components->schemas`. Hand-written schemas take precedence.

5. **`populateServers()`** — If `basePath` set, append `Server` to `$this->openApi->servers`.

6. **`enrichEndpointParameters()`** — If `endpoint_parameters.enabled`, run the enricher. (See [Section 8](#8-endpoint-parameter-enrichment).)

7. **`saveJson()`** — `$this->openApi->saveAs($this->docsFile)`.

8. **`injectSecurity()`** — `SecurityDefinitions` reads JSON, injects config security, writes back. (See [Section 6](#6-security-definitions).)

9. **`makeYamlCopy()`** — If enabled, convert JSON to YAML via Symfony `YamlDumper`, write to YAML file.

### `GeneratorFactory`

```php
class GeneratorFactory
{
    public static function make(string $documentation): OpenApiGenerator
    {
        $config = ConfigFactory::documentationConfig($documentation);

        $dtoConfig = $config['dto'] ?? [];
        $dtoSchemaBuilder = new DtoSchemaBuilder(
            dtoPath: $dtoConfig['path'] ?? app_path('DataObjects'),
            namespace: $dtoConfig['namespace'] ?? 'App\\DataObjects',
            exampleGenerator: new ExampleGenerator(
                $dtoConfig['faker_attribute_mapper'] ?? [],
                $dtoConfig['custom_functions'] ?? [],
            ),
            paginationFields: $dtoConfig['pagination_fields'] ?? [],
        );

        return new OpenApiGenerator(
            annotationsDir: $config['paths']['annotations'] ?? [],
            docsFile: $config['paths']['docs'] . '/' . $config['paths']['docs_json'],
            yamlDocsFile: $config['paths']['docs'] . '/' . $config['paths']['docs_yaml'],
            securitySchemesConfig: $config['security_definitions']['security_schemes'] ?? [],
            securityConfig: $config['security_definitions']['security'] ?? [],
            scanOptions: $config['scan_options'] ?? [],
            constants: $config['constants'] ?? [],
            basePath: $config['paths']['base'] ?? null,
            yamlCopy: $config['generate_yaml_copy'] ?? false,
            endpointParametersConfig: $config['endpoint_parameters'] ?? [],
            dtoSchemaBuilder: $dtoSchemaBuilder,
        );
    }
}
```

### `ConfigFactory`

```php
class ConfigFactory
{
    public static function documentationConfig(string $documentation): array
    {
        $defaults = config('openapi-docs.defaults', []);
        $docConfig = config("openapi-docs.documentations.{$documentation}", []);
        return self::deepMerge($defaults, $docConfig);
    }

    private static function deepMerge(array $base, array $override): array
    {
        // Recursive: associative arrays merged, scalars/indexed arrays replaced
    }
}
```

---

## 6. Security Definitions

Post-generation injection of security schemes from config. Config-defined security is **additive** to annotation-defined security.

```php
namespace Langsys\OpenApiDocsGenerator\Generators;

class SecurityDefinitions
{
    public function __construct(
        private array $securitySchemesConfig,
        private array $securityConfig,
    ) {}

    public function generate(string $docsFile): void
    {
        $json = json_decode(file_get_contents($docsFile), true);
        $this->injectSecuritySchemes($json);
        $this->injectSecurity($json);
        file_put_contents($docsFile, json_encode(
            $json,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }
}
```

**Supports all OpenAPI security types:** `apiKey`, `http`, `oauth2`, `openIdConnect`.

---

## 7. Multiple Documentation Sets

1. **Config:** Each key in `documentations` defines an independent set with its own scan paths, DTO path, and output files.
2. **Merging:** `ConfigFactory::documentationConfig()` deep-merges `defaults` with per-documentation config.
3. **Generation:** Artisan command accepts `{documentation?}` or `--all`.
4. **Separate output:** Each set gets its own JSON/YAML files.

---

## 8. Endpoint Parameter Enrichment

**Unique feature.** Enriches `order_by`/`filter_by` query parameters per-endpoint using data from a pluggable source.

### Why This Exists

The consuming project (Langsys) has a database-driven ordering/filtering system. Every list endpoint supports `order_by` and `filter_by` query parameters, but allowed fields differ per endpoint. This feature replaces generic parameter descriptions with endpoint-specific field lists and defaults.

### Resolver Interface

```php
namespace Langsys\OpenApiDocsGenerator\Contracts;

interface EndpointParameterResolver
{
    public function resolve(string $endpointPath, string $resourceName): ?EndpointParameterData;
}
```

### `EndpointParameterData` DTO

```php
namespace Langsys\OpenApiDocsGenerator\Data;

class EndpointParameterData
{
    public function __construct(
        public array $orderableFields = [],     // ['title', 'created_at', 'admin']
        public array $defaultOrder = [],        // [['admin', 'desc'], ['last_activity_at', 'desc']]
        public array $filterableFields = [],    // ['title', 'status', 'type']
        public array $defaultFilters = [],      // [['status', 'active']]
    ) {}
}
```

### `DatabaseEndpointParameterResolver`

Default implementation. Reads from `api_resources` tables.

**Key behaviors:**
- Checks `Schema::hasTable('api_resources')` on first call (cached for process lifetime)
- Returns `null` if tables don't exist (logs warning once)
- Two-tier lookup: `(name, endpoint)` first, `(name, null)` fallback
- Uses DB facade directly (no Eloquent dependency)

### `EndpointParameterEnricher`

Takes the `OpenApi\Annotations\OpenApi` object and enriches parameters.

**Algorithm:**
1. For each path → each operation (GET, POST, etc.)
2. Infer resource name from 200 response `$ref` (strip `PaginatedResponse`/`ListResponse`/`Response`, append `Resource`)
3. Extract endpoint path (strip leading `/`)
4. Call `resolver->resolve(endpointPath, resourceName)`
5. If data returned, replace `$ref` parameters for `order_by`/`filter_by` with inline parameters containing:
   - Endpoint-specific description with field lists and defaults
   - Updated example using real fields
   - Vendor extensions (`x-orderable-fields`, `x-default-order`, etc.) if enabled

**Description templates:**

For `order_by`:
```
Order results by specified field(s). Supports single field (order_by=field:direction)
or multiple fields for tie-breaking (order_by[]=field1:direction&order_by[]=field2:direction).

**Orderable fields:** `title`, `description`, `admin`, `created_at`, `updated_at`

**Default order:** `admin:desc`, `last_activity_at:desc`
```

For `filter_by`:
```
Filter results by field values. Supports single filter (filter_by=field:value)
or multiple filters (filter_by[]=field1:value&filter_by[]=field2:value).

**Filterable fields:** `title`, `description`, `admin`

**Default filters:** none
```

---

## 9. Artisan Commands

### `openapi:generate`

```
php artisan openapi:generate {documentation?} {--all}
```

- `{documentation?}` — Documentation set name (defaults to `config('openapi-docs.default')`)
- `{--all}` — Generate all documentation sets

Runs the full pipeline: scan annotations → build DTO schemas → enrich → security → write JSON/YAML.

### `openapi:dto`

```
php artisan openapi:dto --model=App\\Models\\User
```

Scaffolds a Spatie Data class from an Eloquent model. (Renamed from `data-swagger:dto`.)

---

## 10. Service Provider & Facade

### `OpenApiDocsServiceProvider`

```php
namespace Langsys\OpenApiDocsGenerator;

class OpenApiDocsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\DtoMakeCommand::class,
                Console\Commands\GenerateCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__ . '/config/openapi-docs.php' => config_path('openapi-docs.php'),
        ], 'config');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/openapi-docs.php', 'openapi-docs');

        $this->app->bind(Generators\OpenApiGenerator::class, function ($app) {
            $documentation = config('openapi-docs.default', 'default');
            return Generators\GeneratorFactory::make($documentation);
        });
    }
}
```

### `OpenApiDocsFacade`

```php
namespace Langsys\OpenApiDocsGenerator;

use Illuminate\Support\Facades\Facade;

class OpenApiDocsFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Generators\OpenApiGenerator::class;
    }
}
```

---

## 11. Implementation Steps

### Phase 1: Core Infrastructure

1. **Create new package structure** — `composer.json` with `langsys/openapi-docs-generator`, namespace `Langsys\OpenApiDocsGenerator`
2. **Create `OpenApiDocsException`**
3. **Create `ConfigFactory`** — Deep merge logic
4. **Write config file** (`openapi-docs.php`)

### Phase 2: DTO Schema Building

5. **Port `ExampleGenerator`** — Keep logic, update namespace
6. **Port `Attributes/`** — Rename base to `OpenApiAttribute`, update namespace
7. **Create `DtoSchemaBuilder`** — Port reflection logic from old `Schema`/`Property`, output `OA\Schema` objects instead of text
8. **Write tests for `DtoSchemaBuilder`** — All type combos

### Phase 3: Document Generation

9. **Create `OpenApiGenerator`** — Main orchestrator
10. **Create `GeneratorFactory`**
11. **Create `SecurityDefinitions`**
12. **Create `GenerateCommand`** — `openapi:generate`

### Phase 4: Endpoint Parameter Enrichment

13. **Create `EndpointParameterData` DTO**
14. **Create `EndpointParameterResolver` interface**
15. **Create `DatabaseEndpointParameterResolver`**
16. **Create `EndpointParameterEnricher`**
17. **Wire enrichment into `OpenApiGenerator` pipeline**

### Phase 5: Integration & Polish

18. **Create `OpenApiDocsServiceProvider`**
19. **Create `OpenApiDocsFacade`**
20. **Port `DtoMakeCommand`** — Rename to `openapi:dto`, update namespace
21. **Port `CustomFunctions`** — Update namespace
22. **Write tests**
23. **Write `CLAUDE.md`** for the new package

---

## 12. Migration Guide for Consuming Projects

### From v1 (`langsys/swagger-auto-generator` + `darkaonline/l5-swagger`)

#### 1. Swap packages
```bash
composer remove langsys/swagger-auto-generator darkaonline/l5-swagger
composer require langsys/openapi-docs-generator
```

#### 2. Publish config
```bash
php artisan vendor:publish --provider="Langsys\OpenApiDocsGenerator\OpenApiDocsServiceProvider" --tag=config
```
Configure: set `documentations.default.paths.annotations`, DTO paths, security, processors, etc.

#### 3. Remove old files
```bash
rm config/l5-swagger.php
rm config/langsys-generator.php
rm app/Swagger/Schemas.php
```

#### 4. Update artisan calls
```php
// Old
Artisan::call('l5-swagger:generate');
Artisan::call('data-swagger:generate', ['--docs' => true]);

// New
Artisan::call('openapi:generate');
```

#### 5. Set up your own UI
The package no longer serves a UI. Point your preferred viewer at the generated `storage/api-docs/api-docs.json`:
- Install Swagger UI standalone
- Use Redocly, Scalar, Stoplight Elements
- Import into Postman

#### 6. Hand-written schemas still work
`@OA\Schema` annotations (e.g. `ManualSchemas.php`) are scanned normally and take precedence over DTO-generated schemas.

#### 7. Enable endpoint parameter enrichment (optional)
```php
'endpoint_parameters' => [
    'enabled' => true,
    'resolver' => \Langsys\OpenApiDocsGenerator\Resolvers\DatabaseEndpointParameterResolver::class,
    'parameters' => ['order_by', 'filter_by'],
    'include_extensions' => true,
    'global_orderable_fields' => ['created_at', 'updated_at'],
],
```

---

## 13. Testing Strategy

### Unit Tests

| Test File | What It Tests |
|-----------|---------------|
| `tests/Unit/DtoSchemaBuilderTest.php` | DTO reflection → OA\Schema for all type combos |
| `tests/Unit/ConfigFactoryTest.php` | Deep merge — associative merge, scalar replacement, nested override |
| `tests/Unit/SecurityDefinitionsTest.php` | Security injection — schemes added, security merged, JSON rewritten |
| `tests/Unit/OpenApiGeneratorTest.php` | Full pipeline — scan + DTO build → validate JSON |
| `tests/Unit/EndpointParameterEnricherTest.php` | Mock OpenAPI object + mock resolver → assert inline parameters |
| `tests/Unit/DatabaseEndpointParameterResolverTest.php` | SQLite in-memory, two-tier lookup, missing tables |

### Integration Tests

| Test File | What It Tests |
|-----------|---------------|
| `tests/Integration/FullPipelineTest.php` | End-to-end: test DTOs + test controller annotations → JSON output |

### Test Data

- `tests/Data/` — Test DTOs (`TestData.php`, `ExampleData.php`, `ExampleEnum.php`)
- `tests/Controllers/` — Test controller with `@OA\Get`/`@OA\Response` annotations
- `tests/Output/expected-api-docs.json` — Gold file for pipeline output

---

## 14. File Inventory

### Files to CREATE

| File | Description |
|------|-------------|
| `src/OpenApiDocsServiceProvider.php` | Service provider |
| `src/OpenApiDocsFacade.php` | Facade |
| `src/Exceptions/OpenApiDocsException.php` | Custom exception |
| `src/Generators/OpenApiGenerator.php` | Main generation orchestrator |
| `src/Generators/DtoSchemaBuilder.php` | DTOs → OA\Schema objects |
| `src/Generators/GeneratorFactory.php` | Creates generator from config |
| `src/Generators/ConfigFactory.php` | Deep-merges config |
| `src/Generators/SecurityDefinitions.php` | Post-gen security injection |
| `src/Generators/EndpointParameterEnricher.php` | Per-endpoint parameter enrichment |
| `src/Generators/ExampleGenerator.php` | Ported — Faker-based examples |
| `src/Generators/Attributes/OpenApiAttribute.php` | Base attribute (ported) |
| `src/Generators/Attributes/Example.php` | Ported |
| `src/Generators/Attributes/Description.php` | Ported |
| `src/Generators/Attributes/Omit.php` | Ported |
| `src/Generators/Attributes/GroupedCollection.php` | Ported |
| `src/Contracts/EndpointParameterResolver.php` | Resolver interface |
| `src/Resolvers/DatabaseEndpointParameterResolver.php` | Default DB resolver |
| `src/Data/EndpointParameterData.php` | Parameter data DTO |
| `src/Console/Commands/GenerateCommand.php` | `openapi:generate` |
| `src/Console/Commands/DtoMakeCommand.php` | `openapi:dto` (ported) |
| `src/Functions/CustomFunctions.php` | Ported |
| `src/config/openapi-docs.php` | Configuration |

### v1 Files NOT carried over

| Old File | Reason |
|----------|--------|
| `SwaggerAutoGeneratorServiceProvider.php` | Replaced by `OpenApiDocsServiceProvider` |
| `Generators/Swagger/SwaggerSchemaGenerator.php` | Replaced by `DtoSchemaBuilder` |
| `Generators/Swagger/Schema.php` | Logic absorbed into `DtoSchemaBuilder` |
| `Generators/Swagger/Property.php` | Logic absorbed into `DtoSchemaBuilder` |
| `Generators/Swagger/PrintsSwagger.php` | No text output in v2 |
| `Generators/Swagger/SwaggerTypeEnum.php` | Unused |
| `Generators/Swagger/Traits/PrettyPrints.php` | No text formatting in v2 |
| `Console/Commands/GenerateDataSwagger.php` | Replaced by `GenerateCommand` |
| `config/langsys-generator.php` | Replaced by `openapi-docs.php` |

---

## Appendix A: Consuming Project Context (Langsys)

### Order/Filter System

Database-driven ordering/filtering. Controllers call `$this->resourceListResponse($collection)` which handles `order_by[]` and `filter_by[]` query params.

**Database tables:** `api_resources`, `resource_orderable_fields`, `resource_default_order_entries`, `resource_filterable_fields`, `resource_default_filters`.

Composite identity `(name, endpoint)` allows per-endpoint config. Resolution: try `(name, endpoint)` first, fall back to `(name, null)`.

### Current Annotations

Base `Controller.php` defines shared `@OA\Parameter` for `order_by`/`filter_by`. The enrichment feature replaces generic refs with endpoint-specific inline parameters.

### Observers

Four observers trigger doc regeneration on DB config changes. After migration: `Artisan::call('openapi:generate')`.

### Other Annotation Sources

- `app/Swagger/ManualSchemas.php` — hand-written schemas (still scanned)
- `app/Swagger/CustomTagOrderProcessor.php` — custom processor (configured in `scan_options.processors`)

## Appendix B: Feature Checklist

- [x] Controller annotation scanning via zircote/swagger-php
- [x] DTO → Schema objects directly (no intermediate annotation text)
- [x] JSON spec output
- [x] YAML spec output
- [x] Custom processor injection (after BuildPaths)
- [x] Processor configuration
- [x] Custom analyser support
- [x] File pattern and exclude configuration
- [x] OpenAPI version configuration (3.0/3.1)
- [x] PHP constant definition for annotations
- [x] Server/base path injection
- [x] Security definitions from config (schemes + requirements)
- [x] Multiple documentation sets with config merging
- [x] Config publishing
- [x] Facade for programmatic access
- [x] Laravel auto-discovery
- [x] Per-endpoint parameter enrichment (order_by/filter_by)
- [x] Generation-only — no UI, no routes, no asset serving
