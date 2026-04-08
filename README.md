# OpenAPI Docs Generator for Laravel

Generate OpenAPI 3.x documentation directly from [Spatie Laravel Data](https://spatie-laravel-data.com/) DTOs. No intermediate annotation files, no UI bundling -- just your DTOs reflected into `api-docs.json` (and optionally YAML), merged with any hand-written controller annotations.

## Table of Contents

- [How It Works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Attributes](#attributes)
  - [#\[Example\]](#example)
  - [#\[Description\]](#description)
  - [#\[Omit\]](#omit)
  - [#\[GroupedCollection\]](#groupedcollection)
- [Supported Property Types](#supported-property-types)
  - [Enum Example](#enum-example)
  - [Nested Data Classes](#nested-data-classes)
  - [DateTime / Carbon](#datetime--carbon)
  - [Optional Properties (`T|Optional`)](#optional-properties-toptional)
- [Collections](#collections)
  - [Laravel Data v4 (Recommended)](#laravel-data-v4-recommended)
  - [Laravel Data v3 (Legacy)](#laravel-data-v3-legacy)
- [Auto-Generated Response Schemas](#auto-generated-response-schemas)
- [Example Generation (Faker)](#example-generation-faker)
- [Artisan Commands](#artisan-commands)
- [Configuration Reference](#configuration-reference)
  - [Multiple Documentation Sets](#multiple-documentation-sets)
  - [Output Paths](#output-paths)
  - [Security Definitions](#security-definitions)
  - [YAML Output](#yaml-output)
  - [Server / Base Path](#server--base-path)
  - [Constants](#constants)
  - [Scan Options](#scan-options)
- [Thunder Client Integration](#thunder-client-integration)
  - [Quick Start](#thunder-client-quick-start)
  - [How It Works](#how-thunder-client-generation-works)
  - [Auth Configuration](#auth-configuration)
  - [Environment File](#environment-file)
  - [Folder Grouping](#folder-grouping)
  - [Request Bodies](#request-bodies)
  - [Merge Behavior](#merge-behavior)
  - [Full Config Reference](#full-thunder-client-config)
- [Viewing Your Docs](#viewing-your-docs)
- [Programmatic Usage](#programmatic-usage)
- [Testing](#testing)
- [License](#license)

## How It Works

```
php artisan openapi:generate
  |
  +-- Scan controller annotations (zircote/swagger-php)
  +-- Reflect on Spatie Data DTOs -> build OpenAPI Schema objects in memory
  +-- Merge DTO schemas into the OpenAPI model
  +-- Inject security definitions from config
  +-- Write api-docs.json / api-docs.yaml
```

DTO-generated schemas are **additive**: if a schema with the same name already exists from your annotations, the annotation version wins.

## Requirements

- PHP 8.1+
- Laravel 10 / 11
- [spatie/laravel-data](https://github.com/spatie/laravel-data) ^3.9 or ^4.0

## Installation

```bash
composer require langsys/openapi-docs-generator
```

Publish the config file:

```bash
php artisan vendor:publish --provider="Langsys\OpenApiDocsGenerator\OpenApiDocsServiceProvider" --tag=config
```

This creates `config/openapi-docs.php`.

## Quick Start

1. Out of the box, the package scans your entire `app/` directory for both controller annotations and Data subclasses. No path or namespace configuration needed — DTOs can live anywhere in your project.

2. Create a Spatie Data class:

```php
namespace App\DataObjects;

use Spatie\LaravelData\Data;

class UserData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?string $phone,
        public bool $is_active = true,
    ) {}
}
```

3. Generate:

```bash
php artisan openapi:generate
```

Output: `storage/api-docs/api-docs.json` with a `UserData` schema containing all properties, types, defaults, and auto-generated example values.

## Attributes

Control how properties appear in the generated schema using PHP attributes on your DTO properties.

### `#[Example]`

Set an explicit example value for a property.

```php
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Example;

class UserData extends Data
{
    public function __construct(
        #[Example(42)]
        public int $id,

        #[Example('jane@example.com')]
        public string $email,

        #[Example(true)]
        public bool $is_admin,
    ) {}
}
```

Produces:

```json
{
  "id": { "type": "integer", "example": 42 },
  "email": { "type": "string", "example": "jane@example.com" },
  "is_admin": { "type": "boolean", "example": true }
}
```

**Faker function reference**: prefix the example value with `:` to call a Faker method directly:

```php
#[Example(':sentence')]
public string $title,

#[Example(':numberBetween', arguments: [1, 100])]
public int $score,
```

### `#[Description]`

Add a description to a property.

```php
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Description;

class UserData extends Data
{
    public function __construct(
        #[Description('The unique user identifier')]
        public int $id,

        #[Description('ISO 8601 date when the account was created')]
        public string $created_at,
    ) {}
}
```

### `#[Omit]`

Exclude a property from the generated schema entirely.

```php
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Omit;

class UserData extends Data
{
    public function __construct(
        public int $id,
        public string $name,

        #[Omit]
        public string $internal_token,  // will NOT appear in the schema
    ) {}
}
```

### `#[GroupedCollection]`

Mark a property as a grouped/dictionary structure. The argument is the key used in the example.

**Simple grouped array** (plain `array` type without a typed docblock):

```php
use Langsys\OpenApiDocsGenerator\Generators\Attributes\GroupedCollection;

class TranslationData extends Data
{
    public function __construct(
        #[GroupedCollection('en')]
        #[Example('Hello')]
        public array $greetings,
    ) {}
}
```

Produces:

```json
{
  "greetings": {
    "type": "object",
    "example": { "en": "Hello" }
  }
}
```

When combined with a typed collection (via `@var` docblock or `#[DataCollectionOf]`), it produces a dictionary-of-arrays structure instead. See [Collections](#collections) for details.

## Supported Property Types

The generator handles these types automatically:

| PHP Type | OpenAPI Output |
|---|---|
| `string` | `{ "type": "string" }` |
| `int` | `{ "type": "integer" }` |
| `float` | `{ "type": "number" }` |
| `bool` | `{ "type": "boolean" }` |
| `array`, `Collection` | `{ "type": "array", "items": { ... } }` |
| `SomeData` (nested Data class) | `{ "$ref": "#/components/schemas/SomeData" }` |
| `SomeData[]` via `@var` docblock (v4) | `{ "type": "array", "items": { "$ref": "..." } }` |
| `Collection<int, SomeData>` via `@var` docblock (v4) | `{ "type": "array", "items": { "$ref": "..." } }` |
| `DataCollection` with `#[DataCollectionOf]` (v3) | `{ "type": "array", "items": { "$ref": "..." } }` |
| `BackedEnum` | `{ "type": "string", "enum": ["case1", "case2"] }` |
| `?BackedEnum` (nullable enum) | `{ "type": "string", "enum": [...], "nullable": true }` |
| `Carbon`, `DateTime`, etc. | `{ "type": "string", "format": "date-time" }` |
| `string\|Optional` (Laravel Data) | `{ "type": "string" }` — excluded from `required` |
| Nullable (`?string`) | Tracked as not required |
| Default values | Included as `"default": value` |

### Enum Example

```php
enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
}

class UserData extends Data
{
    public function __construct(
        #[Example('active')]
        public UserStatus $status = UserStatus::Active,
    ) {}
}
```

Produces:

```json
{
  "status": {
    "type": "string",
    "default": "active",
    "enum": ["active", "inactive", "suspended"],
    "example": "active"
  }
}
```

If `#[Example]` is missing or its value isn't a valid enum case, a random case is picked automatically.

### Nested Data Classes

```php
class AddressData extends Data
{
    public function __construct(
        public string $street,
        public string $city,
    ) {}
}

class UserData extends Data
{
    public function __construct(
        public string $name,
        public AddressData $address,
    ) {}
}
```

Both `AddressData` and `UserData` schemas are generated. The `address` property uses `$ref`:

```json
{ "address": { "$ref": "#/components/schemas/AddressData" } }
```

### DateTime / Carbon

Properties typed as `Carbon`, `CarbonImmutable`, `DateTime`, `DateTimeImmutable`, or any `DateTimeInterface` implementation are automatically rendered as `type: "string"` with `format: "date-time"` — matching Laravel Data's default ISO 8601 serialization.

```php
use Carbon\Carbon;

class EventData extends Data
{
    public function __construct(
        public string $title,
        public Carbon $starts_at,
        public ?Carbon $cancelled_at = null,

        #[Example('2025-12-31T23:59:59+00:00')]
        public Carbon $deadline,
    ) {}
}
```

Produces:

```json
{
  "starts_at": { "type": "string", "format": "date-time", "example": "2024-01-15T10:30:00+00:00" },
  "cancelled_at": { "type": "string", "format": "date-time", "nullable": true },
  "deadline": { "type": "string", "format": "date-time", "example": "2025-12-31T23:59:59+00:00" }
}
```

Without this, `Carbon` and `DateTime` would be treated as nested objects with a `$ref` — which is incorrect since Laravel Data serializes them as ISO 8601 strings.

### Optional Properties (`T|Optional`)

[Spatie Laravel Data's `Optional`](https://spatie.be/docs/laravel-data/v4/as-a-data-transfer-object/optional-properties) type is used in request DTOs to mark fields that can be omitted entirely from the payload (the "sometimes" validation rule). The generator strips `Optional` from union types and excludes the property from the schema's `required` array.

```php
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class UpdateUserRequest extends Data
{
    public function __construct(
        public string $id,                        // required
        public string|Optional $name,             // optional — type resolves to string
        public Optional|string $email,            // union order doesn't matter
        public int|Optional $age,                 // optional — type resolves to integer
        public UserStatus|Optional $status = UserStatus::Active,  // optional enum with default
    ) {}
}
```

Produces a schema where only `id` is in the `required` array, and each optional property uses its underlying type:

```json
{
  "schema": "UpdateUserRequest",
  "required": ["id"],
  "properties": {
    "id": { "type": "integer" },
    "name": { "type": "string" },
    "email": { "type": "string" },
    "age": { "type": "integer" },
    "status": { "type": "string", "enum": ["active", "inactive"], "default": "active" }
  }
}
```

> **Note**: `Optional` is different from nullable (`?string`). Nullable means the field can be present with a `null` value. `Optional` means the field can be absent from the request entirely. Both result in the property being excluded from `required`, but they represent different semantics.

## Collections

### Laravel Data v4 (Recommended)

In Laravel Data v4, the recommended way to type collections is with `@var` docblock annotations on plain `array` or `Collection` properties. The generator parses these docblocks and produces typed array schemas automatically.

**Array with `ClassName[]`:**

```php
class OrderData extends Data
{
    public function __construct(
        public string $order_number,

        /** @var OrderItemData[] */
        public array $items,
    ) {}
}
```

**Collection with generic syntax:**

```php
use Illuminate\Support\Collection;

class OrderData extends Data
{
    public function __construct(
        /** @var Collection<int, OrderItemData> */
        public Collection $items,
    ) {}
}
```

Both produce:

```json
{
  "items": {
    "type": "array",
    "items": { "$ref": "#/components/schemas/OrderItemData" }
  }
}
```

**Grouped collection (v4 style):**

Combine `@var` docblock with `#[GroupedCollection]` for dictionary-of-arrays output:

```php
class CatalogData extends Data
{
    public function __construct(
        /** @var ProductData[] */
        #[GroupedCollection('electronics')]
        public array $products_by_category,
    ) {}
}
```

Produces:

```json
{
  "products_by_category": {
    "type": "object",
    "additionalProperties": {
      "type": "array",
      "items": { "$ref": "#/components/schemas/ProductData" }
    }
  }
}
```

Non-Data types like `string[]` or `int[]` in docblocks are ignored and fall through to plain array handling.

### Laravel Data v3 (Legacy)

The v3 pattern using `DataCollection` with `#[DataCollectionOf]` is still fully supported:

```php
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Attributes\DataCollectionOf;

class OrderData extends Data
{
    public function __construct(
        #[DataCollectionOf(OrderItemData::class)]
        public DataCollection $items,
    ) {}
}
```

Grouped collections with v3:

```php
class CatalogData extends Data
{
    public function __construct(
        #[GroupedCollection('electronics')]
        #[DataCollectionOf(ProductData::class)]
        public DataCollection $products_by_category,
    ) {}
}
```

Both patterns produce identical OpenAPI output. If you're migrating from v3 to v4, you can update your DTOs incrementally -- existing `DataCollection` properties continue to work alongside new `@var` docblock properties.

## Auto-Generated Response Schemas

Any DTO whose class name ends with `Resource` automatically gets three additional wrapper schemas:

| Class | Generated Schemas |
|---|---|
| `ProjectResource` | `Project`, `ProjectResponse`, `ProjectPaginatedResponse`, `ProjectListResponse` |

**`ProjectResponse`**: `{ status: bool, data: Project }`

**`ProjectListResponse`**: `{ status: bool, data: [Project] }`

**`ProjectPaginatedResponse`**: `{ status, page, records_per_page, page_count, total_records, data: [Project] }`

The pagination wrapper fields are configured via `dto.pagination_fields`:

```php
'pagination_fields' => [
    ['name' => 'status', 'description' => 'Response status', 'content' => true, 'type' => 'bool'],
    ['name' => 'page', 'description' => 'Current page number', 'content' => 1, 'type' => 'int'],
    ['name' => 'records_per_page', 'description' => 'Records per page', 'content' => 8, 'type' => 'int'],
    ['name' => 'page_count', 'description' => 'Number of pages', 'content' => 5, 'type' => 'int'],
    ['name' => 'total_records', 'description' => 'Total items', 'content' => 40, 'type' => 'int'],
],
```

## Example Generation (Faker)

When a property doesn't have an explicit `#[Example]` attribute, the generator produces example values automatically using Faker. It uses three resolution strategies in order:

### 1. Faker Attribute Mapper

Maps property name patterns to Faker methods. If a property name contains the pattern, the corresponding Faker method is called.

```php
// config/openapi-docs.php
'faker_attribute_mapper' => [
    'address_1' => 'streetAddress',   // $user->address_1 -> Faker::streetAddress()
    'address_2' => 'buildingNumber',  // $user->address_2 -> Faker::buildingNumber()
    'zip'       => 'postcode',        // $user->zip_code  -> Faker::postcode()
    '_at'       => 'date',            // $user->created_at -> Faker::date()
    '_url'      => 'url',             // $user->avatar_url -> Faker::url()
    'locale'    => 'locale',          // $user->locale     -> Faker::locale()
    'phone'     => 'phoneNumber',     // $user->phone      -> Faker::phoneNumber()
    '_id'       => 'id',              // $user->user_id    -> custom 'id' function
],
```

The matching is substring-based: a property named `created_at` matches `_at` and uses `Faker::date()`.

### 2. Custom Functions

For cases where Faker doesn't have what you need, register custom functions:

```php
// config/openapi-docs.php
'custom_functions' => [
    'id' => [\Langsys\OpenApiDocsGenerator\Functions\CustomFunctions::class, 'id'],
    'date' => [\Langsys\OpenApiDocsGenerator\Functions\CustomFunctions::class, 'date'],
],
```

The built-in `CustomFunctions` class provides:

- **`id`**: returns a UUID for string types, a random integer for int types
- **`date`**: returns a `Y-m-d H:i:s` formatted date string (or timestamp for int types)

To add your own, create a class and register it:

```php
namespace App\OpenApi;

class MyCustomFunctions
{
    public function currency(string $type): string
    {
        return collect(['USD', 'EUR', 'GBP'])->random();
    }

    public function percentage(string $type): int|string
    {
        return $type === 'int' ? random_int(0, 100) : random_int(0, 100) . '%';
    }
}
```

```php
'custom_functions' => [
    'currency' => [App\OpenApi\MyCustomFunctions::class, 'currency'],
    'percentage' => [App\OpenApi\MyCustomFunctions::class, 'percentage'],
],
```

Custom functions receive the property type as their first argument.

### 3. Direct Faker Fallback

If no mapper pattern matches and no custom function exists, the property name itself is tried as a Faker method (converted to camelCase). So a property named `first_name` automatically calls `Faker::firstName()`. If that fails, it falls back to `0` for integers or an empty string for everything else.

### Invoking Faker Directly from `#[Example]`

You can reference any Faker method from the `#[Example]` attribute by prefixing with `:`:

```php
#[Example(':sentence')]
public string $title,

#[Example(':numberBetween', arguments: [1, 1000])]
public int $score,

#[Example(':email')]
public string $contact_email,
```

## Artisan Commands

### `openapi:generate`

Generate OpenAPI documentation from controller annotations and DTO schemas.

```bash
# Generate docs for the default documentation set
php artisan openapi:generate

# Generate docs for a specific documentation set
php artisan openapi:generate v2

# Generate docs for all documentation sets
php artisan openapi:generate --all

# Also generate a Thunder Client collection (see Thunder Client section)
php artisan openapi:generate --thunder-client
```

### `openapi:thunder`

Generate a Thunder Client collection as a standalone command (requires `api-docs.json` to already exist).

```bash
# Generate for the default documentation set
php artisan openapi:thunder

# Generate for a specific documentation set
php artisan openapi:thunder v2
```

See [Thunder Client Integration](#thunder-client-integration) for full details.

### `openapi:dto`

Scaffold a Spatie Data class from an Eloquent model. Reads the database schema and generates typed properties.

```bash
php artisan openapi:dto --model=App\\Models\\User
```

Generates `app/DataObjects/UserData.php`:

```php
namespace App\DataObjects;

use Spatie\LaravelData\Data;

final class UserData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?string $email_verified_at,
        public string $password,
        public ?string $remember_token,
        public ?string $created_at,
        public ?string $updated_at,
    ) {}
}
```

On PHP 8.2+, properties are generated as `readonly`.

### `openapi:make-processor`

Generate a swagger-php processor class. Processors let you customize how annotations are processed during scanning -- the most common use case is controlling the order tags appear in Swagger UI.

```bash
# Create App\Swagger\TagOrderProcessor (default)
php artisan openapi:make-processor

# Custom name and namespace
php artisan openapi:make-processor SortEndpointsProcessor --namespace=App\\OpenApi
```

The command creates the file and prints the config snippet you need to add. See [Scan Options / Processors](#processors) for details.

## Configuration Reference

All configuration lives in `config/openapi-docs.php`. The file has two main sections:

- **`documentations`** -- per-documentation-set overrides (file names, scan paths)
- **`defaults`** -- shared settings inherited by all documentation sets

Every key under `defaults` can be overridden per documentation set via deep merge (associative arrays are merged recursively, scalars and indexed arrays are replaced).

### Multiple Documentation Sets

Useful for API versioning or separating public/internal APIs. Each documentation set can override any value from `defaults`.

```php
'documentations' => [
    'v1' => [
        'paths' => [
            'docs_json' => 'v1-api-docs.json',
            'annotations' => [app_path('Http/Controllers/V1'), app_path('DataObjects/V1')],
        ],
    ],
    'v2' => [
        'paths' => [
            'docs_json' => 'v2-api-docs.json',
            'annotations' => [app_path('Http/Controllers/V2'), app_path('DataObjects/V2')],
        ],
    ],
],
```

The `annotations` directories are scanned for both controller annotations **and** Data subclasses — one config, one scan.

Generate a specific set with `php artisan openapi:generate v2`, or all sets with `--all`.

### Output Paths

```php
'paths' => [
    // Directory where api-docs.json and api-docs.yaml are written
    'docs' => storage_path('api-docs'),

    // Base server URL added to the OpenAPI servers list (null = no server entry)
    'base' => env('OPENAPI_BASE_PATH', null),

    // Directories to exclude from scanning
    'excludes' => [],
],
```

The `docs_json` and `docs_yaml` filenames are set per documentation set (under `documentations`), not in defaults. They default to `api-docs.json` and `api-docs.yaml`.

### Security Definitions

Inject OpenAPI security schemes and global security requirements into the generated `api-docs.json`. These define how your API authenticates and appear in the `components/securitySchemes` and top-level `security` sections of the spec.

```php
'security_definitions' => [
    // Define authentication schemes your API supports.
    // These appear in components/securitySchemes in the OpenAPI output.
    'security_schemes' => [
        // Bearer token (e.g. Laravel Sanctum)
        'bearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'description' => 'Enter token in format: Bearer <token>',
        ],

        // API key sent as a custom header
        'apiKey' => [
            'type' => 'apiKey',
            'name' => 'X-Authorization',   // header name
            'in' => 'header',              // where the key is sent
            'description' => 'API key for machine-to-machine access',
        ],

        // OAuth2 (e.g. Laravel Passport)
        // 'passport' => [
        //     'type' => 'oauth2',
        //     'flows' => [
        //         'password' => [
        //             'authorizationUrl' => '/oauth/authorize',
        //             'tokenUrl' => '/oauth/token',
        //             'refreshUrl' => '/oauth/token/refresh',
        //             'scopes' => [],
        //         ],
        //     ],
        // ],
    ],

    // Global security requirements applied to all endpoints by default.
    // Each entry references a scheme name from above.
    // Endpoints can override this with their own @OA\Security annotation.
    'security' => [
        // ['bearerAuth' => []],
    ],
],
```

**Precedence**: if you define a security scheme with the same name both in config and via a `@OA\SecurityScheme` annotation, the annotation version wins.

**Note**: these settings control what appears in your OpenAPI spec. They are separate from the [Thunder Client auth config](#auth-configuration), which controls how Thunder Client requests are set up.

### YAML Output

Generate a YAML copy alongside the JSON output:

```php
'generate_yaml_copy' => true,
```

Or via environment variable:

```env
OPENAPI_GENERATE_YAML=true
```

### Server / Base Path

Add a server entry to the OpenAPI output. This tells API consumers (Swagger UI, Postman, etc.) the base URL for your API.

```php
'paths' => [
    'base' => env('OPENAPI_BASE_PATH', 'https://api.example.com/v1'),
],
```

When set, the generated JSON includes:

```json
{ "servers": [{ "url": "https://api.example.com/v1" }] }
```

When `null`, no `servers` section is added.

### Constants

Define PHP constants that can be referenced inside `@OA\*` annotations. Useful for injecting environment-specific values into your documentation.

```php
'constants' => [
    'API_HOST' => env('API_HOST', 'http://localhost'),
    'API_VERSION' => 'v1',
],
```

Use in annotations:

```php
#[OA\Server(url: API_HOST)]
#[OA\Info(title: 'My API', version: API_VERSION)]
```

### Scan Options

Controls how [zircote/swagger-php](https://github.com/zircote/swagger-php) scans your codebase for annotations.

```php
'scan_options' => [
    'processors' => [],
    'exclude' => [],
    'open_api_spec_version' => env('OPENAPI_SPEC_VERSION', '3.0.0'),
],
```

#### `exclude`

Directories or files to skip when scanning for annotations. Paths are relative to the annotation directories.

```php
'exclude' => [
    'app/Http/Controllers/Internal',
    'app/Http/Controllers/Admin',
],
```

#### `open_api_spec_version`

Which OpenAPI specification version to generate. Defaults to `3.0.0`.

```php
'open_api_spec_version' => '3.1.0',
```

#### Processors

Processors are classes that run after swagger-php parses your annotations but before the final OpenAPI document is built. They let you modify, reorder, or enrich the parsed data.

The most common use case is **controlling tag order in Swagger UI**. By default, tags appear in whatever order swagger-php discovers them (which depends on file scan order). A processor lets you define an explicit order.

**Generate a processor:**

```bash
php artisan openapi:make-processor
```

This creates `app/Swagger/TagOrderProcessor.php`:

```php
namespace App\Swagger;

use OpenApi\Analysis;
use OpenApi\Annotations\OpenApi;
use OpenApi\Annotations\Tag;

class TagOrderProcessor
{
    public function __invoke(Analysis $analysis): void
    {
        if (!isset($analysis->openapi)) {
            return;
        }

        /** @var OpenApi $openapi */
        $openapi = $analysis->openapi;

        // Define your tags in the order you want them to appear in Swagger UI.
        // Each name must match a tag used in your controller annotations.
        // Example: #[OA\Get(tags: ['Users'])]
        $openapi->tags = [
            new Tag(['name' => 'Auth']),
            new Tag(['name' => 'Users']),
            new Tag(['name' => 'Projects']),
            new Tag(['name' => 'Billing']),
            // Add all your tags here in the desired order...
        ];
    }
}
```

**Register it in config:**

```php
'scan_options' => [
    'processors' => [
        new \App\Swagger\TagOrderProcessor(),
    ],
],
```

Custom processors are injected after swagger-php's `BuildPaths` processor, so all paths and operations are already resolved when your processor runs. You can pass either a class instance or a class name string.

## Thunder Client Integration

Generate [Thunder Client](https://www.thunderclient.com/) collections directly from your OpenAPI documentation. The generator reads your `api-docs.json` and creates a ready-to-use `tc_col_{slug}.json` file that Thunder Client auto-loads -- click "Send" immediately, no manual request setup needed.

### Thunder Client Quick Start

1. Generate your OpenAPI docs first (if not already done):

```bash
php artisan openapi:generate
```

2. Generate the Thunder Client collection:

```bash
# As a separate step
php artisan openapi:thunder

# Or combined with doc generation
php artisan openapi:generate --thunder-client
```

3. Open VS Code -- Thunder Client automatically detects files in `thunder-tests/` and loads the collection.

The generator only creates requests for endpoints that exist in your `api-docs.json`. If a controller method doesn't have OpenAPI annotations, it won't appear in the collection.

### How Thunder Client Generation Works

The generator reads the already-generated `api-docs.json` and:

1. Creates a folder for each API tag (e.g. "Users", "Projects")
2. Creates a request for each endpoint with the correct method, URL, headers, auth, and body
3. Converts OpenAPI path parameters (`{id}`) to Thunder Client variables (`{{id}}`)
4. Prefixes all URLs with a configurable base URL variable (`{{url}}/api/users`)
5. Builds request bodies from schema examples for POST/PUT/PATCH endpoints
6. Writes the collection to `thunder-tests/collections/tc_col_{slug}.json`
7. Optionally generates an environment file with variables from `.env`

### Auth Configuration

The `thunder_client.auth` config maps your OpenAPI security scheme names to Thunder Client auth settings. Each key must match a scheme name used in your OpenAPI `security` annotations.

```php
'thunder_client' => [
    'auth' => [
        // Bearer token auth (e.g. Sanctum, Passport)
        // Sets auth type to "bearer" in Thunder Client.
        // The actual token value is stored in your Thunder Client environment
        // as the variable named in 'token_variable'.
        'bearerAuth' => [
            'type' => 'bearer',
            'token_variable' => 'token',  // TC environment variable name
        ],

        // API key sent as a custom header
        // Adds the header to the request and sets auth type to "none"
        // (since auth is handled via the header itself).
        'apiKey' => [
            'type' => 'header',
            'header_name' => 'X-Authorization',  // header added to request
            'value' => '{{api_key}}',             // TC variable reference
        ],

        // Basic auth
        // Sets auth type to "basic" in Thunder Client.
        // Username/password are configured in Thunder Client's auth tab.
        // 'basicAuth' => [
        //     'type' => 'basic',
        // ],
    ],

    // When an endpoint has no security annotation, this scheme is used.
    // Set to 'none' to leave requests unauthenticated by default.
    'default_auth' => 'bearerAuth',
],
```

**How scheme matching works:**

1. The generator reads each endpoint's `security` field from the OpenAPI JSON (e.g. `"security": [{"bearerAuth": []}]`)
2. It looks up each scheme name in `thunder_client.auth` to determine how to configure the Thunder Client request
3. If an endpoint references a scheme name that isn't in your config, it's skipped with a warning
4. If an endpoint has no `security` field, `default_auth` is used

**Multiple auth schemes on one endpoint:**

If an endpoint supports multiple auth methods (e.g. both bearer and API key), the generator creates **one request per scheme**, with the scheme name appended to the request name:

```
List Users (bearerAuth)
List Users (apiKey)
```

If only one scheme is used, no suffix is added.

### Environment File

Optionally generate a Thunder Client environment file (`tc_env_{slug}.json`) with variables pre-populated from your `.env`:

```php
'thunder_client' => [
    'environment' => [
        'slug' => 'local',       // filename: tc_env_local.json
        'name' => 'Local',       // display name in Thunder Client

        // Map Thunder Client variable names to values.
        // 'env:KEY' reads the value from your Laravel .env file.
        // Any other value is used as-is (empty string = user fills in manually).
        'variables' => [
            'url' => 'env:APP_URL',       // reads APP_URL from .env
            'token' => '',                 // empty -- user pastes their token
            'api_key' => 'env:API_KEY',   // reads API_KEY from .env
        ],

        // Appended to the base URL variable when its value comes from env:
        // e.g. APP_URL=http://localhost -> url=http://localhost/api
        'url_suffix' => '/api',
    ],
],
```

The environment file is **only created once**. If `tc_env_local.json` already exists, it is never overwritten -- you manage your own environment variables after initial creation.

Set `'environment' => null` to skip environment generation entirely. You can always create environments manually in Thunder Client.

### Folder Grouping

Requests are grouped into folders using the **first tag** from each endpoint's OpenAPI `tags` array:

```php
// In your controller:
#[OA\Get(path: '/api/users', tags: ['Users'])]
//                                    ^^^^^^^
// -> Goes into "Users" folder in Thunder Client
```

**Fallback when no tags**: the generator infers a folder name from the URL path. It takes the first meaningful segment after skipping common prefixes:

| Path | Skip segments | Folder |
|---|---|---|
| `/api/users/{id}` | `api` | Users |
| `/api/v1/projects` | `api`, `v1` | Projects |
| `/billing/invoices` | (none to skip) | Billing |

Configure which segments to skip:

```php
'skip_path_segments' => ['api', 'v1', 'v2', 'v3'],
```

### Request Bodies

For POST, PUT, and PATCH endpoints, the generator builds a JSON request body from the schema defined in the endpoint's `requestBody`:

- Resolves `$ref` references to component schemas (up to 3 levels deep)
- Uses `example` values from schema properties when available
- Falls back to sensible defaults: `""` for strings, `0` for integers, `false` for booleans, `[]` for arrays
- Uses the first `enum` value when a property has an enum constraint
- Merges `allOf` sub-schemas

The body is stored as a JSON string in the request, ready to edit and send.

### Merge Behavior

The generator **never overwrites existing requests**. When you run it again after adding new endpoints:

- Existing requests, folders, and all their data are preserved untouched
- The collection `_id` is reused (so Thunder Client treats it as the same collection)
- Only new endpoints (by method + URL path) are appended
- New folders are created only if needed for new requests

This means you can safely customize requests in Thunder Client (add tests, change bodies, etc.) and re-run the generator without losing your changes.

### Full Thunder Client Config

```php
'thunder_client' => [
    // Thunder Client workspace root directory.
    // Collections are written to {output_dir}/collections/
    // Environment files are written to {output_dir}/
    'output_dir' => base_path('thunder-tests'),

    // Slug used in the collection filename: tc_col_{slug}.json
    // Use a descriptive name if you have multiple collections.
    'collection_slug' => 'api',

    // Display name shown in Thunder Client's collection list.
    // When null, uses the 'info.title' from your OpenAPI spec.
    'collection_name' => null,

    // Thunder Client variable name used as the base URL prefix.
    // All request URLs are generated as: {{url}}/path/here
    // The actual value of this variable is set in your TC environment.
    'base_url_variable' => 'url',

    // Auth scheme mappings -- see "Auth Configuration" section above.
    'auth' => [
        'sanctum' => [
            'type' => 'bearer',
            'token_variable' => 'token',
        ],
    ],

    // Default auth scheme when an endpoint has no security annotation.
    // Must match a key in the 'auth' array above, or 'none'.
    'default_auth' => 'sanctum',

    // Environment file generation -- see "Environment File" section above.
    // Set to null to skip.
    'environment' => [
        'slug' => 'local',
        'name' => 'Local',
        'variables' => [
            'url' => 'env:APP_URL',
        ],
        'url_suffix' => '/api',
    ],

    // URL path segments to ignore when inferring folder names from paths.
    // Only used as a fallback when endpoints have no OpenAPI tags.
    'skip_path_segments' => ['api', 'v1', 'v2', 'v3'],

    // Headers added to every generated request.
    // Uses 'name'/'value' format (not 'key'/'value').
    'default_headers' => [
        ['name' => 'Accept', 'value' => 'application/json'],
    ],
],
```

## Viewing Your Docs

This package generates files only. To view them, use any OpenAPI-compatible viewer:

- [Swagger UI](https://swagger.io/tools/swagger-ui/) (standalone or Docker)
- [Scalar](https://github.com/scalar/scalar)
- [Redocly](https://redocly.com/)
- [Stoplight Elements](https://github.com/stoplightio/elements)
- Import `api-docs.json` into [Postman](https://www.postman.com/) or [Thunder Client](https://www.thunderclient.com/)

## Programmatic Usage

Use the facade to generate docs from code:

```php
use Langsys\OpenApiDocsGenerator\OpenApiDocsFacade as OpenApiDocs;

OpenApiDocs::generateDocs();
```

Or resolve from the container:

```php
use Langsys\OpenApiDocsGenerator\Generators\OpenApiGenerator;

app(OpenApiGenerator::class)->generateDocs();
```

For a specific documentation set:

```php
use Langsys\OpenApiDocsGenerator\Generators\GeneratorFactory;

GeneratorFactory::make('v2')->generateDocs();
```

Thunder Client generation programmatically:

```php
use Langsys\OpenApiDocsGenerator\Generators\ThunderClientFactory;

ThunderClientFactory::make('default')->generate();
```

## Testing

```bash
./vendor/bin/pest
```

## License

MIT
