# Generate Thunder Client Collections from OpenAPI Docs

## Context

Users want to click "Send" in Thunder Client without manually creating requests. The generator reads the already-generated `api-docs.json`, builds Thunder Client requests for **documented endpoints only** (those with OpenAPI annotation stubs on the controller method), and writes to a `tc_col_{name}.json` file in the Thunder Client workspace directory. Existing requests are never overwritten; only missing ones are appended.

## Thunder Client File Format (Workspace / Database v3)

Single file `tc_col_{slug}.json` in `thunder-tests/` (default) — auto-loaded by Thunder Client:

```json
{
    "_id": "uuid",
    "colName": "My API",
    "created": "2024-10-09T02:57:45.684Z",
    "sortNum": 10000,
    "folders": [
        { "_id": "uuid", "name": "Users", "containerId": "", "created": "...", "sortNum": 10000 }
    ],
    "requests": [
        {
            "_id": "uuid",
            "colId": "collection-uuid",
            "containerId": "folder-uuid-or-empty",
            "name": "List Users",
            "url": "{{url}}/users",
            "method": "GET",
            "sortNum": 10000,
            "created": "...",
            "modified": "...",
            "headers": [{ "name": "Accept", "value": "application/json" }],
            "body": { "type": "json", "raw": "", "form": [] },
            "auth": { "type": "bearer" },
            "tests": []
        }
    ]
}
```

Key format details (verified from real projects):
- Root is a **single object** (not array)
- Headers use `name` field (not `key`)
- `body.raw` is a **string** (JSON-encoded)
- Auth `{ type: "bearer" }` — token stored in Thunder Client environment, not in collection
- Filename: `tc_col_{configurable-slug}.json`
- Default directory: `thunder-tests/collections/` at project root

## Files to Create

- `src/Generators/ThunderClientGenerator.php` — Core generator
- `src/Generators/ThunderClientFactory.php` — Factory wiring from config
- `src/Console/Commands/ThunderClientCommand.php` — Standalone `openapi:thunder {documentation?}` command
- `tests/Unit/ThunderClientGeneratorTest.php` — Unit tests

## Files to Modify

- `src/Console/Commands/GenerateCommand.php` — Add `{--thunder-client}` flag
- `src/OpenApiDocsServiceProvider.php` — Register `ThunderClientCommand`
- `src/config/openapi-docs.php` — Add `thunder_client` config section

## Config Structure

```php
'thunder_client' => [
    // Directory for Thunder Client workspace files
    'output_dir' => base_path('thunder-tests'),

    // Slug used in filename: tc_col_{slug}.json
    'collection_slug' => 'api',

    // Collection display name (null = use OpenAPI info.title)
    'collection_name' => null,

    // Base URL variable name (used as {{variable}} in URLs)
    'base_url_variable' => 'url',

    // Authentication schemes (keyed by name, matched to OpenAPI securitySchemes)
    // Each scheme defines how to configure auth in Thunder Client requests.
    // When an endpoint references a scheme by name, the matching config here is used.
    // If an endpoint has MULTIPLE schemes, one request is generated per scheme.
    'auth' => [
        'sanctum' => [
            'type' => 'bearer',               // Thunder Client auth type
            'token_variable' => 'token',       // TC environment variable name
        ],
        // 'api_key' => [
        //     'type' => 'header',             // sends as a custom header
        //     'header_name' => 'X-Authorization',
        //     'value' => '{{api_key}}',       // TC environment variable reference
        // ],
    ],

    // Default auth to apply when an operation has no security defined
    // Use a scheme name from above, or 'none'
    'default_auth' => 'sanctum',

    // Environment generation (optional)
    // When enabled, generates a tc_env_{slug}.json file from .env values.
    // Set to null/false to skip — users can create environments manually
    // and just reference variable names in the auth config above.
    'environment' => [
        // Slug for the environment file: tc_env_{slug}.json
        'slug' => 'local',

        // Display name in Thunder Client
        'name' => 'Local',

        // Map of TC variable name => .env key (or literal value)
        // If the value starts with 'env:' it reads from .env, otherwise used as-is
        'variables' => [
            'url' => 'env:APP_URL',           // reads APP_URL from .env, appends /api
            // 'token' => '',                  // empty = user fills in manually
            // 'api_key' => 'env:API_KEY',     // reads API_KEY from .env
        ],

        // Suffix to append to the url variable (e.g. '/api')
        'url_suffix' => '/api',
    ],

    // Path segments to skip when inferring folder names (fallback when no tags)
    'skip_path_segments' => ['api', 'v1', 'v2', 'v3'],

    // Default headers to include on every request
    'default_headers' => [
        ['name' => 'Accept', 'value' => 'application/json'],
    ],
],
```

## ThunderClientGenerator Design

### Constructor
```php
public function __construct(
    private string $openApiFile,       // path to api-docs.json
    private string $collectionFile,    // full path to tc_col_{slug}.json
    private ?string $collectionName,   // display name
    private array $authSchemes,        // keyed auth scheme configs
    private string $defaultAuth,       // default scheme name or 'none'
    private string $baseUrlVariable,   // e.g. 'url'
    private array $skipPathSegments,
    private array $defaultHeaders,
    private ?array $environmentConfig, // null = skip env generation
    private ?string $environmentFile,  // full path to tc_env_{slug}.json
)
```

### generate() Pipeline

1. **Load OpenAPI JSON** — only endpoints present in `paths` are processed (documented ones)
2. **Load existing collection** — read `tc_col_{slug}.json` if it exists, otherwise null
3. **Resolve collection shell** — reuse existing `_id`/`colName` or create new with UUID
4. **Build folders from tags** — use operation `tags[0]` for folder name. Fall back to path segment inference if no tags
5. **Build requests** — for each path + method + operation in OpenAPI:
   - **URL**: `{{url}}/path` with OpenAPI `{param}` → `{{param}}`
   - **Name**: operation `summary` if present, otherwise `"METHOD /path"`
   - **Headers**: default headers from config + `Content-Type: application/json` for POST/PUT/PATCH
   - **Auth**: per-operation security schemes. For **each** scheme referenced, create a separate request. If only one scheme, no suffix in name. If multiple, suffix with type: "(Bearer)", "(API Key)"
   - **Body**: for POST/PUT/PATCH, resolve requestBody schema → build JSON from example values
   - **Folder**: `containerId` = folder `_id` based on tag (or path fallback)
6. **Merge** — skip requests whose `method + normalized URL` already exist in the collection
7. **Write collection** — save to `tc_col_{slug}.json` with pretty-printed JSON
8. **Generate environment** (optional) — if `environmentConfig` is set and `tc_env_{slug}.json` doesn't already exist, create it with variables mapped from `.env` or literal values. Never overwrites an existing environment file.

### No-Overwrite Merge Logic

- Build lookup from existing requests: key = `METHOD|/normalized/path`
- URL normalization: strip `{{base_url_variable}}` prefix, strip trailing slashes
- When an endpoint already exists (any auth variant), skip ALL new variants for that method+path
- Preserve existing folders, requests, and all their data completely untouched
- Reuse existing collection `_id` as `colId` for new requests

### Auth Handling

For each operation:

1. **Determine applicable schemes**: operation-level `security` overrides global `security` from the OpenAPI JSON. Each entry in the security array references a scheme name (e.g. `sanctum`, `api_key`).
2. **Look up each scheme** in config `thunder_client.auth` by name. This is where users define how each scheme translates to Thunder Client (bearer auth, custom header, etc.).
3. **Generate one request per scheme**:
   - **`type: bearer`**: sets `auth: { type: "bearer" }` on the request. Token lives in TC environment variable (`token_variable`).
   - **`type: header`**: adds a custom header (`header_name` with `value`). Sets `auth: { type: "none" }`. E.g. `X-Authorization: {{api_key}}`.
   - **`type: basic`**: sets `auth: { type: "basic" }`.
4. **Naming**:
   - If only one scheme → no suffix (e.g. "List Users")
   - If multiple schemes → suffix with scheme name in parens (e.g. "List Users (sanctum)", "List Users (api_key)")
5. **No security defined**: use `default_auth` from config. If `default_auth` is `'none'`, set `auth: { type: "none" }`.
6. **Scheme not in config**: if an OpenAPI scheme name has no matching config entry, skip it with a warning (don't generate a broken request).

### Request Body Construction

For POST/PUT/PATCH with `requestBody.content.application/json.schema`:
1. Resolve `$ref` if present (depth limit 3, circular ref protection)
2. Walk schema `properties`, use `example` values when available
3. Type fallbacks: `""` for string, `0` for integer, `false` for boolean, `[]` for array, `{}` for object
4. Use first `enum` value if present
5. Handle `allOf`: merge properties from all sub-schemas
6. Return `{ type: "json", raw: json_encode($body, PRETTY_PRINT), form: [] }`

### Folder Grouping

**Primary**: Use operation `tags[0]` as folder name (OpenAPI tag → Thunder Client folder)

**Fallback** (no tags): Extract first path segment after skipping configured segments, `ucfirst()` it. E.g., `/api/users/{id}` → "Users"

**Root**: Requests without a determinable folder go to collection root (`containerId: ""`)

### Environment Generation (Optional)

If `thunder_client.environment` is configured and `tc_env_{slug}.json` does **not** already exist:

1. Create a TC environment file with the configured `name` and variables
2. For each entry in `variables`:
   - If value starts with `env:` (e.g. `env:APP_URL`), read the value from Laravel's `.env` file
   - Otherwise use the value as a literal (empty string = user fills in manually in Thunder Client)
3. If `url_suffix` is set and the `base_url_variable` entry uses `env:`, append the suffix to the resolved value (e.g. `http://localhost` + `/api` = `http://localhost/api`)

Output format (`tc_env_{slug}.json`):
```json
{
    "_id": "uuid",
    "name": "Local",
    "default": true,
    "sortNum": 10000,
    "created": "2026-03-10T00:00:00.000Z",
    "modified": "2026-03-10T00:00:00.000Z",
    "data": [
        { "name": "url", "value": "http://localhost/api" },
        { "name": "token", "value": "" },
        { "name": "api_key", "value": "" }
    ]
}
```

**Never overwrites** — if the file already exists, skip entirely. Users manage their own environments after initial creation.

If `thunder_client.environment` is `null` or not set, no environment file is generated. Users can create environments manually in Thunder Client and just reference variable names in the `auth` and `base_url_variable` config.

## GenerateCommand Changes

```php
protected $signature = 'openapi:generate {documentation?} {--all} {--thunder-client}';
```

In `generateDocumentation()`, after successful `generateDocs()`:
```php
if ($this->option('thunder-client')) {
    ThunderClientFactory::make($documentation)->generate();
    $this->info("Thunder Client collection updated.");
}
```

## ThunderClientFactory

```php
class ThunderClientFactory
{
    public static function make(string $documentation): ThunderClientGenerator
    {
        $config = ConfigFactory::documentationConfig($documentation);
        $tc = $config['thunder_client'] ?? [];

        $outputDir = $tc['output_dir'] ?? base_path('thunder-tests');
        $slug = $tc['collection_slug'] ?? 'api';
        $collectionFile = $outputDir . '/tc_col_' . $slug . '.json';

        $docsDir = $config['paths']['docs'] ?? storage_path('api-docs');
        $docsJson = $config['paths']['docs_json'] ?? 'api-docs.json';
        $openApiFile = $docsDir . '/' . $docsJson;

        // Environment file (optional)
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
        );
    }
}
```

## Tests

### ThunderClientGeneratorTest.php
- Generates valid `tc_col_*.json` from OpenAPI spec with correct root structure
- Converts `{param}` to `{{param}}` in URLs
- Prefixes URLs with `{{url}}` (configurable base_url_variable)
- Groups requests into folders by OpenAPI tags
- Falls back to path segment inference when no tags
- Skips configured path segments for folder names
- Uses operation summary as request name, falls back to "METHOD /path"
- Builds request body from schema examples with `$ref` resolution
- Sets bearer auth on requests (scheme type: bearer)
- Sets custom header auth on requests (scheme type: header, e.g. X-Authorization)
- Creates one request per security scheme when endpoint has multiple schemes
- Suffixes request name with scheme name when multiple schemes
- No suffix when single scheme
- Uses default_auth when operation has no security defined
- Skips unknown scheme names (not in config) gracefully
- Does NOT overwrite existing requests (merge test)
- Preserves existing collection _id, folders, requests on merge
- Adds only new folders for new requests on merge
- Handles empty paths gracefully
- Uses config `collection_name` over OpenAPI `info.title` when set
- Only processes documented endpoints (those present in OpenAPI paths)
- Generates environment file from env: variables when configured
- Does NOT overwrite existing environment file
- Skips environment generation when config is null
- Resolves env: prefix variables from .env
- Appends url_suffix to base URL variable

### Extend FullPipelineTest.php
- `--thunder-client` option generates both `api-docs.json` and `tc_col_*.json`

## Implementation Order

1. Config: add `thunder_client` section to `src/config/openapi-docs.php`
2. `ThunderClientGenerator.php` — core logic
3. `ThunderClientFactory.php` — wiring from config
4. `ThunderClientCommand.php` — standalone command
5. Modify `GenerateCommand.php` — add `--thunder-client` flag
6. Register in `ServiceProvider`
7. Tests

## Branch

Create `feature/thunder-client-generator` before starting implementation.

## Verification

```bash
./vendor/bin/pest
```

All 53 existing tests pass unchanged. New Thunder Client tests pass.
