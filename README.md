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
  - [#\[ItemType\] / #\[OneOfItemsFrom\]](#itemtype--oneofitemsfrom)
- [Supported Property Types](#supported-property-types)
  - [Enum Example](#enum-example)
  - [Nested Data Classes](#nested-data-classes)
  - [DateTime / Carbon](#datetime--carbon)
  - [Optional Properties (`T|Optional`)](#optional-properties-toptional)
- [Collections](#collections)
  - [Laravel Data v4 (Recommended)](#laravel-data-v4-recommended)
  - [Laravel Data v3 (Legacy)](#laravel-data-v3-legacy)
- [Auto-Generated Response Schemas](#auto-generated-response-schemas)
- [API Errors](#api-errors)
  - [The Error Class Contract](#the-error-class-contract)
  - [The Error Response](#the-error-response)
  - [Attaching Errors to Operations](#attaching-errors-to-operations)
  - [Validation Scenarios](#validation-scenarios)
- [Example Generation (Faker)](#example-generation-faker)
- [Artisan Commands](#artisan-commands)
- [Configuration Reference](#configuration-reference)
  - [Multiple Documentation Sets](#multiple-documentation-sets)
  - [Filtered Documentation Sets](#filtered-documentation-sets)
  - [Clean Output (Automatic Pruning)](#clean-output-automatic-pruning)
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
  +-- (Filtered sets) Select operations by their route's middleware
  +-- Reflect on Spatie Data DTOs -> build OpenAPI Schema objects in memory
  +-- Discover error classes (subclasses of errors.base_class) -> error schemas and reusable responses
  +-- Merge DTO and error schemas into the OpenAPI model
  +-- Attach error responses to operations (#[Throws], implied_errors, rules)
  +-- Scope error docs to the errors operations reference (even with pruning off)
  +-- Prune components/tags nothing references (clean output)
  +-- Inject security definitions from config
  +-- Write api-docs.json / api-docs.yaml
```

DTO-generated schemas are **additive**: if a schema with the same name already exists from your annotations, the annotation version wins. The error steps are covered in [API Errors](#api-errors).

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

### `#[ItemType]` / `#[OneOfItemsFrom]`

Declare a polymorphic array whose items can be one of several DTO variants — block-based content (Notion / Tiptap style), event envelopes, or any tagged-union payload.

- `#[ItemType('group', ?handle)]` is applied to a Data **class**. It registers that class as a possible variant in a named group. The optional `handle` defaults to the snake-cased basename of the schema (with `Resource` / `Data` suffix stripped).
- `#[OneOfItemsFrom('group')]` is applied to an array property. The generator emits the property as `array<oneOf<...>>` where each `oneOf` member is a generated wrapper schema named `{Variant}Item` with shape `{ type: <handle>, data: <Variant> }`.

```php
use Langsys\OpenApiDocsGenerator\Generators\Attributes\ItemType;
use Langsys\OpenApiDocsGenerator\Generators\Attributes\OneOfItemsFrom;

#[ItemType('blocks')] // handle inferred as "paragraph"
class ParagraphResource extends Data { /* ... */ }

#[ItemType('blocks', 'picture')] // handle overridden to "picture"
class ImageResource extends Data { /* ... */ }

class BlockContainerResource extends Data
{
    public function __construct(
        public string $title,
        #[OneOfItemsFrom('blocks')]
        public array $content,
    ) {}
}
```

For each variant, the generator emits a wrapper schema (`ParagraphItem`, `ImageItem`) that the property's `oneOf` references. Abstract Data subclasses are skipped from auto-schema generation, so a shared `AbstractBlockResource` base will not produce its own schema.

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

## API Errors

Document machine-readable API errors from Spatie Data classes, one class per failure mode. Point the generator at your abstract error base class, and every concrete subclass becomes a documented error:

```php
'errors' => [
    'base_class' => \App\Http\Errors\ApiError::class,
],
```

### The Error Class Contract

An error class declares its identity with three constants:

```php
abstract class ApiError extends Data {}

class NotFoundError extends ApiError
{
    public const CODE = 'not_found';
    public const MESSAGE = 'Resource not found';
    public const STATUS = 404;          // or an int-backed enum case, e.g. HttpCode::NOT_FOUND
}

class ProjectNotFoundError extends NotFoundError
{
    public const CODE = 'project_not_found';
    public const MESSAGE = 'No project with that id';
    // STATUS is inherited from NotFoundError
}
```

| Constant | Type | Rule |
|---|---|---|
| `CODE` | string | The slug clients branch on. Every concrete error class declares its own. |
| `MESSAGE` | string | The default human message, and the documentation text. Every concrete error class declares its own. May contain [`{marker}` placeholders](#message-templates). |
| `STATUS` | int, or an int-backed enum | The HTTP status. May be inherited, which is what a specific error's parent is for. |

Typed class constants such as `public const string CODE` work too, on PHP 8.3 and later.

Constants inherit silently, so a subclass that forgot to redeclare `CODE` would share its parent's code without anyone noticing. Generation therefore fails, before anything is written, when:

- a concrete error class inherits `CODE` or `MESSAGE` instead of declaring it;
- two error classes declare the same `CODE`;
- no `STATUS` can be resolved, or it is not an HTTP status between 100 and 599;
- an error class carries a class-level `#[Description]`, because `MESSAGE` already documents it;
- an `#[EnvelopeField]` property reuses an error-object field name;
- `MESSAGE` contains a `{marker}` that names no public property of the class.

Public properties are the error's typed details. These attributes work with errors:

| Attribute | Target | Purpose |
|---|---|---|
| `#[EnvelopeField]` | property | Emit this property at the top level of the `error` object instead of under `details`, e.g. a validation `errors` map. |
| `#[Description('…')]` | property | Describe a details or envelope property. |
| `#[Throws(Error::class, …)]` | method, class | Declare which errors a controller action, or every action of a controller, can return. |

### The Error Response

An error response carries everything inside one `error` object:

```json
{
  "status": false,
  "data": [],
  "error": {
    "message": "Insufficient balance to complete this request",
    "code": "insufficient_balance",
    "template": "Insufficient balance to complete this request",
    "details": { "required": 500, "available": 120 }
  }
}
```

The `data` key suits apps whose success and error responses share one envelope. If yours doesn't, set `response_fields.data` to `null` and error responses become `{ status, error }`.

For each error the operations reference, the generator emits:

- **`InsufficientBalanceError`**: the details schema, built from the class's non-envelope properties. Omitted when there are none.
- **`InsufficientBalanceErrorBody`**: the error object. `message` carries `MESSAGE` as its `example`, `code` is an enum of the one code, `template` is `MESSAGE` verbatim, `params` documents its markers when it has any, `details` references the details schema, and `#[EnvelopeField]` properties follow. `message`, `code` and `template` are required.
- **`InsufficientBalanceErrorResponse`**: the envelope. `status` is always false, `data` is an always-empty array unless you drop it, and `error` references the body. `status` and `error` are required.
- **`components.responses.InsufficientBalanceError`**: a reusable response with `application/json` content and an `x-http-status` extension.
- **`ErrorCode`**: one string enum of the referenced codes, whose description lists each code with its HTTP status and `MESSAGE`. It survives [pruning](#clean-output-automatic-pruning) as the error-codes reference page even though nothing references it directly.

#### Message templates

`MESSAGE` is source text that may contain `{marker}` placeholders, so a client can translate it and then fill in the values:

```php
class PlanLimitError extends ApiError
{
    public const CODE = 'plan_limit_reached';
    public const MESSAGE = 'Your plan allows up to {limit} projects.';
    public const STATUS = 402;

    public function __construct(
        #[Example(5)]
        public int $limit,
    ) {}
}
```

A marker is a lowercase name in braces, such as `{limit}` or `{plan_name}`, and it must name a public property of the class. Generation fails otherwise, naming the class and the marker. The error body then carries:

- **`template`**: `MESSAGE` verbatim, the source text a client translates. Always present.
- **`params`**: an object with one property per marker, each documented like any DTO property from the same-named class property, including its type, `#[Description]` and `#[Example]`. Required when `MESSAGE` has markers, and omitted when it has none.
- **`message`**: the source text with the values filled in. Its example substitutes each marker's `#[Example]` value; a marker without one stays as written.

```json
"error": {
  "message": "Your plan allows up to 5 projects.",
  "code": "plan_limit_reached",
  "template": "Your plan allows up to {limit} projects.",
  "params": { "limit": 5 },
  "details": { "limit": 5 }
}
```

Set `error_fields.template` or `error_fields.params` to `null` to leave either out.

Every error response's description carries ``` `code`: MESSAGE ```, e.g. ``` `too_many_requests`: Too many requests. Please try again later. ``` A status with one error shows that single line; a status several errors share shows the same lines under a `Possible errors:` header.

**Only referenced errors are documented.** An error no operation can return is an internal failure mode, not API surface. Its schemas and response are left out whether or not `prune_unused_components` is on, and the `ErrorCode` enum lists exactly the errors that remain, so each documentation set publishes an honest code list. An error counts as referenced when an operation reaches it through `#[Throws]`, `implied_errors`, or a hand-written `$ref`. With pruning off, a schema you keep that references an error keeps that error too, so no reference is ever left dangling.

The schemas are open, with no `additionalProperties: false`. An app can add fields it chooses not to document, such as debug output for allow-listed developers, without failing response validation.

A validation error typically lifts its field map into the error object:

```php
class ValidationFailedError extends ApiError
{
    public const CODE = 'validation_failed';
    public const MESSAGE = 'The given data was invalid';
    public const STATUS = 422;

    public function __construct(
        /** @var array<string, string[]> */
        #[EnvelopeField]
        public array $errors,
    ) {}
}
// => { status, data, error: { message, code, errors: { <field>: [string] } } }
```

A `@var array<string, T>` docblock on an `array` property is emitted as an `object` with `additionalProperties` (T scalar, `T[]`, or `mixed` for a free-form object); other arrays stay lists.

Field names are configurable per documentation set under `errors`, so each app can keep its own naming. A `null` name omits that field. Unknown keys and duplicate names fail generation, so a typo cannot silently fall back to a default:

```php
'errors' => [
    'base_class' => \App\Http\Errors\ApiError::class, // or a list; null disables errors
    'paths' => null,            // extra directories to scan; null = same as DTO discovery
    'code_schema' => 'ErrorCode',

    // Response level. `error` cannot be null.
    'response_fields' => [
        'status' => 'status',
        'data' => 'data',       // null drops the always-empty key: { status, error }
        'error' => 'error',
    ],

    // Inside the error object. `code` cannot be null.
    'error_fields' => [
        'message' => 'message',
        'code' => 'code',
        'template' => 'template',
        'params' => 'params',
        'details' => 'details',
    ],
],
```

### Attaching Errors to Operations

Rather than repeating the same 401/422/404 response blocks on every action, declare what an action can return and let the generator attach the responses.

`#[Throws]` on the action is the explicit list. On a controller class it applies to every action of that controller:

```php
use Langsys\OpenApiDocsGenerator\Generators\Attributes\Throws;

class ProjectController
{
    #[OA\Post(path: '/api/projects/{project}/purchase', responses: [...])]
    #[Throws(InsufficientBalanceError::class, ValidationError::class)]
    public function purchase(Project $project, PurchaseRequest $request) { … }
}
```

Errors can also be implied, so they never have to be repeated at all. Configure `implied_errors` per documentation set:

```php
'implied_errors' => [
    // Middleware alias or FQCN => errors any route carrying it can return.
    'middleware' => [
        'auth:sanctum' => [UnauthenticatedError::class],
        'deduct.request' => [InsufficientBalanceError::class],
    ],

    // Action takes a Spatie Data parameter => this error.
    'validation' => ValidationFailedError::class,

    // Route has a bound {param} => this error.
    'not_found' => NotFoundError::class,

    // 'model' (default): only params bound to an Eloquent model, implicitly by the
    // action's signature or by Route::bind(). 'any': any {param} in the route URI.
    'not_found_binding' => 'model',
],
```

When a convention lives somewhere the framework cannot prove — an in-body authorization call, a permission registry, a service contract — write a rule instead of a heuristic in config. A rule implements `Contracts\ImpliedErrorRule` and receives the operation, its resolved route and its reflected action:

```php
use App\Http\Errors\ForbiddenError;
use Langsys\OpenApiDocsGenerator\Contracts\ImpliedErrorRule;
use Langsys\OpenApiDocsGenerator\Data\OperationContext;
use ReflectionMethod;

class AuthorizesRule implements ImpliedErrorRule
{
    public function errorsFor(OperationContext $context, ?ReflectionMethod $action): array
    {
        if ($action === null || $action->getFileName() === false) {
            return [];
        }

        // Only the action's own source lines, not the whole controller.
        $lines = array_slice(
            file($action->getFileName()),
            $action->getStartLine() - 1,
            $action->getEndLine() - $action->getStartLine() + 1,
        );

        return str_contains(implode('', $lines), 'AccessGuard::authorize(')
            ? [ForbiddenError::class]
            : [];
    }
}

// 'implied_errors' => ['rules' => [AuthorizesRule::class]],
```

Descriptors accept a class name, `['class' => …, 'args' => […]]`, or an instance. The library ships no such rule on purpose: a heuristic over an implementation idiom belongs with the app that owns the idiom, where a refactor that breaks it is visible.

Middleware is matched against the route's fully-resolved middleware — the same ground truth [filtered sets](#filtered-documentation-sets) use — so it works however the middleware was attached (directly, by group, by alias, or by class).

An operation's errors are the union of all sources, deduplicated by class, then grouped by HTTP status:

| Errors for the status | Emitted response |
|---|---|
| One | `$ref` to `#/components/responses/{Name}` |
| Several | Inline response with the envelope's `status` and `data`, whose `error` property is a `oneOf` of their `{Name}Body` schemas with `discriminator: { propertyName: code, mapping: { … } }`. The discriminator sits on `error` because OpenAPI 3.0 only discriminates on a top-level property of each variant. |

A response the author wrote for that status always wins, the same precedence DTO schemas follow. Generation fails when `#[Throws]`, `implied_errors` or a rule names a class that is not a documented error: not a concrete subclass of `errors.base_class`, or never scanned. The message says which.

### Validation Scenarios

`implied_errors.validation` gives an operation one error for its validation status, so every endpoint reads the same flat message. If your app knows which field can fail and why, a resolver can put that in the docs:

```php
use Langsys\OpenApiDocsGenerator\Contracts\ValidationScenarioResolver;
use Langsys\OpenApiDocsGenerator\Data\OperationContext;
use Langsys\OpenApiDocsGenerator\Data\ValidationScenario;
use ReflectionMethod;

class FieldErrorScenarios implements ValidationScenarioResolver
{
    public function errorsFor(): array { /* … */ }

    public function scenariosFor(OperationContext $context, ?ReflectionMethod $action): array
    {
        // Walk the action's request rules, however your app defines them.
        return [
            new ValidationScenario('credit_card.cc_number', 'already_taken', 'This credit card has already been added.'),
            // One code, two rules, two sentences: both are listed.
            new ValidationScenario('locale', 'invalid_option', 'The locale is not valid.'),
            new ValidationScenario('locale', 'invalid_option', 'The locale is not a target locale of this project.'),
            // A rule that judges something outside the payload belongs to no field: pass null.
            new ValidationScenario(null, 'expired', 'This invitation has expired.'),
        ];
    }
}

// 'implied_errors' => ['validation_scenarios' => FieldErrorScenarios::class],
```

Descriptors accept a class name, `['class' => …, 'args' => […]]`, an instance, or a list of those, exactly like `rules`.

An operation with scenarios gets an inline validation response instead of the shared `$ref`, keeping the same `{Name}Response` schema and listing the scenarios in its description:

```
`validation_failed`: The request failed validation.

Possible validation errors:

- `credit_card.cc_number`.`already_taken`: This credit card has already been added.
- `locale`.`invalid_option`: The locale is not valid.
- `locale`.`invalid_option`: The locale is not a target locale of this project.
- `expired`: This invitation has expired.
```

- Identical scenarios are dropped, but one code carrying different messages is kept: several rules legitimately share a code, and the message is what a reader acts on. Order is preserved.
- A status shared by several errors keeps its `oneOf` and gains the same block.
- A hand-written response for that status still wins and gets nothing attached.
- An operation whose resolver returns nothing is documented exactly as before, and so is every endpoint when no resolver is configured.
- `implied_errors.validation` must be set, since the scenarios are listed on that error's response. Returning scenarios without it fails generation, naming the operation.

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

### Filtered Documentation Sets

A documentation set can emit only a **subset** of your API — for example an `integration` spec containing just the endpoints an API key can call. Selection is correct by construction: only the chosen operations, and the schemas, parameters and tags they reference, are emitted.

Operations are matched to their Laravel route and selected by a pluggable filter. The default discriminator is **route middleware** — ground truth, unlike hand-written `security` annotations, which drift from what your routes actually enforce.

```php
'documentations' => [
    'integration' => [
        'paths'  => ['docs_json' => 'api-docs-integration.json'],
        'filter' => [
            'include' => [
                ['middleware' => 'auth.apikey'],
            ],
        ],
        'security_override' => [['apiKey' => []]],
    ],
],
```

Generate it with `php artisan openapi:generate integration` (or `--all`). The command prints a summary so you can eyeball the result:

```
Filtered set 'integration': kept 16, dropped 133 operation(s).
```

#### How operations are matched

Each documented operation is resolved to its Laravel route **action-first**: the controller action (e.g. `App\Http\Controllers\ProjectController@index`) maps exactly to the route, which avoids brittle path-string matching. If an operation has no controller action (closure routes, hand-written path-only annotations), it falls back to matching the HTTP method + path signature — parameter names are ignored, so a documented `/projects/{id}` still matches a `/projects/{project}` route.

The filter then inspects the route's **fully-resolved middleware** (route groups expanded, aliases resolved to their classes), so `['middleware' => 'auth.apikey']` matches whether the middleware was attached directly, via a group, by alias, or by class name.

#### Filter descriptors

`include` and `exclude` are lists of descriptors. An operation is kept when it matches **any** `include` descriptor and **no** `exclude` descriptor. An empty (or absent) `include` means "all operations are candidates".

| Descriptor | Matches |
|---|---|
| `['middleware' => 'auth.apikey']` | operations whose route has the middleware (alias or FQCN; add `'match' => 'all'` to require every item of a list) |
| `['tag' => 'Public']` | operations carrying the tag |
| `['path' => 'webhooks/*']` | operations whose path matches the glob |
| `['operationId' => 'listProjects']` | operations with the operationId |
| `['class' => App\Docs\MyFilter::class, 'args' => []]` | a custom filter (see below) |

```php
'filter' => [
    'include' => [ ['middleware' => 'auth.apikey'] ],
    'exclude' => [ ['tag' => 'Internal'] ],

    // What to do with operations that can't be matched to a route:
    // 'exclude' (default) or 'include'.
    'unmatched' => 'exclude',
],
```

Operations that can't be matched to any route are reported and, by default, excluded — an operation with no known route can't be proven to satisfy a middleware filter. `openapi:generate` lists any such operations, so annotation-vs-route drift is never silent.

#### Consistent auth display (`security_override`)

Because a filtered set is matched by real middleware, its operations should display the matching auth — not whatever `security` annotation happens to be written on them. `security_override` forces the security requirement on every surviving operation, sets the global requirement, and restricts `components/securitySchemes` to only the schemes it names:

```php
'security_override' => [['apiKey' => []]],
```

The `integration` spec above then shows `apiKey` on every operation and advertises only the `apiKey` scheme — even if the controllers were annotated with `bearerAuth`.

#### Per-set identity (`info`)

All sets share a single `@OA\Info` annotation, but a subset spec usually wants its own title and description. Add an `info` key to a documentation set to override those fields **for that set only**. It is deep-merged over the scanned `@OA\Info`, so fields you don't specify (`version`, `contact`, `license`, …) fall back to the annotation:

```php
'integration' => [
    // ...filter, security_override...
    'info' => [
        'title'       => 'Langsys Integration API',
        'description' => 'The API-key-authenticated subset of the Langsys API.',
    ],
],
```

Supported fields: `title`, `description`, `version`, `termsOfService`, `summary`, and (deep-merged) `contact` / `license`. The `default` set, declaring no `info`, keeps the annotation's values untouched.

#### Custom filters

Implement `Langsys\OpenApiDocsGenerator\Contracts\OperationFilter`:

```php
use Langsys\OpenApiDocsGenerator\Contracts\OperationFilter;
use Langsys\OpenApiDocsGenerator\Data\OperationContext;

class DeprecatedFilter implements OperationFilter
{
    public function matches(OperationContext $context): bool
    {
        return $context->operation->deprecated === true;
    }
}
```

`OperationContext` carries the operation, its path and HTTP method, and the resolved route — `$context->route?->middleware()` gives the fully-resolved middleware list — so a custom filter can match on anything. Wire it up with `['class' => DeprecatedFilter::class]`.

### Clean Output (Automatic Pruning)

Every generated document is **pruned** to only the schemas, parameters, responses, security schemes and tags reachable from its operations — so a spec never carries schemas nothing references. This is on by default for all sets (and always on for filtered sets).

To keep every discovered DTO schema in a set even when nothing references it, opt out per set:

```php
'documentations' => [
    'catalog' => [
        'paths' => ['docs_json' => 'catalog.json'],
        'prune_unused_components' => false,   // keep all schemas
    ],
],
```

> **Upgrade note (v2.6.0):** previously every discovered DTO appeared in the spec. Now a DTO that no documented operation references is pruned from the output. Set `prune_unused_components => false` on a set to restore the old "include everything" behavior.

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
