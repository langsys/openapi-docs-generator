# OpenAPI Docs Generator for Laravel

Generate OpenAPI 3.x documentation directly from [Spatie Laravel Data](https://spatie-laravel-data.com/) DTOs. No intermediate annotation files, no UI bundling -- just your DTOs reflected into `api-docs.json` (and optionally YAML), merged with any hand-written controller annotations.

## How It Works

```
php artisan openapi:generate
  |
  +-- Scan controller annotations (zircote/swagger-php)
  +-- Reflect on Spatie Data DTOs -> build OpenAPI Schema objects in memory
  +-- Merge DTO schemas into the OpenAPI model
  +-- Enrich endpoint parameters (optional)
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

**Simple grouped array** (plain `array` type):

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

**Grouped DataCollection** (combined with `#[DataCollectionOf]`):

```php
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Attributes\DataCollectionOf;

class CategoryData extends Data
{
    public function __construct(
        #[GroupedCollection('en')]
        #[DataCollectionOf(ItemData::class)]
        public DataCollection $items_by_locale,
    ) {}
}
```

Produces:

```json
{
  "items_by_locale": {
    "type": "object",
    "additionalProperties": {
      "type": "array",
      "items": { "$ref": "#/components/schemas/ItemData" }
    }
  }
}
```

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
| `DataCollection` with `#[DataCollectionOf]` | `{ "type": "array", "items": { "$ref": "..." } }` |
| `BackedEnum` | `{ "type": "string", "enum": ["case1", "case2"] }` |
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

```bash
# Generate docs for the default documentation set
php artisan openapi:generate

# Generate docs for a specific documentation set
php artisan openapi:generate v2

# Generate docs for all documentation sets
php artisan openapi:generate --all
```

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

## Configuration Reference

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

### Security Definitions

Inject security schemes and global security requirements from config. These are **additive** to any `@OA\SecurityScheme` annotations.

```php
'security_definitions' => [
    'security_schemes' => [
        'sanctum' => [
            'type' => 'apiKey',
            'description' => 'Enter token in format: Bearer <token>',
            'name' => 'Authorization',
            'in' => 'header',
        ],
    ],
    'security' => [
        ['sanctum' => []],
    ],
],
```

Annotation-defined security schemes with the same name take precedence over config-defined ones.

### YAML Output

```php
'generate_yaml_copy' => true,
```

Or via environment variable:

```env
OPENAPI_GENERATE_YAML=true
```

### Server / Base Path

```php
'paths' => [
    'base' => env('OPENAPI_BASE_PATH', 'https://api.example.com/v1'),
],
```

### OpenAPI Spec Version

```php
'scan_options' => [
    'open_api_spec_version' => '3.1.0',  // default: '3.0.0'
],
```

### Constants

Define PHP constants available inside `@OA\*` annotations:

```php
'constants' => [
    'API_HOST' => env('API_HOST', 'http://localhost'),
],
```

Use in annotations: `@OA\Server(url=API_HOST)`.

### Scan Options

```php
'scan_options' => [
    'exclude' => ['tests', 'vendor'],       // directories to exclude
    'pattern' => '*.php',                    // file pattern
    'processors' => [                        // custom processors (injected after BuildPaths)
        App\Swagger\CustomProcessor::class,
    ],
    'analyser' => null,                      // custom analyser instance
],
```

## Endpoint Parameter Enrichment

An optional feature that replaces generic `order_by` / `filter_by` query parameter `$ref`s with endpoint-specific inline parameters containing the actual allowed fields and defaults.

### Setup

1. Create the required database tables (`api_resources`, `resource_orderable_fields`, `resource_default_order_entries`, `resource_filterable_fields`, `resource_default_filters`).

2. Enable in config:

```php
'endpoint_parameters' => [
    'enabled' => true,
    'resolver' => \Langsys\OpenApiDocsGenerator\Resolvers\DatabaseEndpointParameterResolver::class,
    'parameters' => ['order_by', 'filter_by'],
    'include_extensions' => true,
    'global_orderable_fields' => ['created_at', 'updated_at'],
],
```

### How It Works

For each operation in the generated OpenAPI spec:

1. Infers the resource name from the 200 response `$ref` (e.g. `ProjectPaginatedResponse` -> `ProjectResource`)
2. Queries the resolver for that endpoint's orderable/filterable fields
3. Replaces generic `$ref` parameters with inline parameters containing field lists and defaults

### Custom Resolver

Implement the `EndpointParameterResolver` interface to use a different data source:

```php
use Langsys\OpenApiDocsGenerator\Contracts\EndpointParameterResolver;
use Langsys\OpenApiDocsGenerator\Data\EndpointParameterData;

class MyResolver implements EndpointParameterResolver
{
    public function resolve(string $endpointPath, string $resourceName): ?EndpointParameterData
    {
        return new EndpointParameterData(
            orderableFields: ['title', 'created_at'],
            defaultOrder: [['created_at', 'desc']],
            filterableFields: ['status', 'type'],
            defaultFilters: [['status', 'active']],
        );
    }
}
```

## Viewing Your Docs

This package generates files only. To view them, use any OpenAPI-compatible viewer:

- [Swagger UI](https://swagger.io/tools/swagger-ui/) (standalone or Docker)
- [Scalar](https://github.com/scalar/scalar)
- [Redocly](https://redocly.com/)
- [Stoplight Elements](https://github.com/stoplightio/elements)
- Import `api-docs.json` into [Postman](https://www.postman.com/)

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

## Testing

```bash
./vendor/bin/pest
```

## License

MIT
