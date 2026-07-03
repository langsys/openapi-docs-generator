# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel package (`langsys/openapi-docs-generator`) that generates OpenAPI/Swagger documentation directly from Spatie Laravel Data DTOs. Instead of generating intermediate annotation text files, it builds `OpenApi\Annotations\Schema` objects in memory and merges them with controller annotations scanned by zircote/swagger-php. Outputs JSON and optionally YAML.

## Commands

```bash
# Install dependencies
composer install

# Run tests (Pest framework via Orchestra Testbench)
./vendor/bin/pest

# Run a single test file
./vendor/bin/pest tests/Unit/DtoSchemaBuilderTest.php

# Run a specific test by name
./vendor/bin/pest --filter="handles enum properties"

# Artisan commands (in a Laravel app)
php artisan openapi:generate              # Generate docs for default documentation set
php artisan openapi:generate v2           # Generate docs for a specific documentation set
php artisan openapi:generate --all        # Generate docs for all documentation sets
php artisan openapi:dto --model=User      # Generate a Data class from an Eloquent model
```

There are no composer scripts defined — use `./vendor/bin/pest` directly.

## Architecture

### Generation Pipeline

`OpenApiGenerator` orchestrates the pipeline:
1. `prepareDirectory()` — Ensure output directory exists and is writable
2. `defineConstants()` — Define PHP constants from config for use in annotations
3. `scanFilesForDocumentation()` — Use zircote/swagger-php to scan controller annotations
4. `selectOperations()` — (Filtered sets only) Resolve each operation's route and keep only those matching the set's `OperationFilter`s; returns a `SelectionReport`
5. `applySecurityOverride()` — (Filtered sets only) Force the configured `security_override` onto every surviving operation
6. `buildAndMergeDtoSchemas()` — Build DTO schemas via `DtoSchemaBuilder` and merge into OpenAPI model (annotation-defined schemas take precedence)
7. `enrichEndpointParameters()` — Replace generic `$ref` parameters with endpoint-specific inline parameters
8. `pruneComponentsAndTags()` — Remove components/tags outside the transitive `$ref` closure of the surviving operations. On by default so no document ships unused schemas; opt out per non-filtered set with `prune_unused_components => false` (filtered sets always prune)
9. `populateServers()` — Add server entries from config
10. `saveJson()` — Save OpenAPI model as JSON
11. `injectSecurity()` — Inject security definitions from config into JSON (restricted to the override's schemes when `security_override` is set)
12. `makeYamlCopy()` — Optionally convert JSON to YAML

Note: `generate()` returns the fully-assembled `OA\OpenApi` tree, so every step after step 3 mutates that in-memory model. Filtering is therefore post-scan (the discriminator, route middleware, is not present in the scanned annotations). Clean output is guaranteed by the prune step, not by a reference-driven build: `buildAndMergeDtoSchemas()` builds all DTOs, then `pruneComponentsAndTags()` removes everything outside the reference closure. This build-all-then-prune order is deliberate — it can never emit a dangling `$ref` (pruning only removes unreachable components), whereas a reference-driven "build only the closure" would risk under-building. A lazy closure build is a possible future perf optimization, but only with an added `$ref`-resolution validation pass; it is not needed for correctness.

### Key Classes (under `Langsys\OpenApiDocsGenerator`)

- **Generators\OpenApiGenerator** — Main orchestrator. Runs the 9-step pipeline.
- **Generators\GeneratorFactory** — Factory that wires all dependencies from config and returns an `OpenApiGenerator`.
- **Generators\DtoSchemaBuilder** — Core class. Scans a directory for Spatie `Data` subclasses, reflects on their properties, and builds `OA\Schema` objects directly in memory. Handles enums (including nullable enums), nested objects, collections, grouped collections, arrays, DateTime/Carbon (as `string` with `date-time` format), and primitives. Strips `Spatie\LaravelData\Optional` from union types and marks those properties as not required. Auto-generates Response/PaginatedResponse/ListResponse wrappers for Resource DTOs.
- **Generators\ExampleGenerator** — Produces example values using Faker, with configurable attribute mapping (property name patterns → Faker methods) and custom function overrides.
- **Generators\ConfigFactory** — Deep-merges `defaults` config with per-documentation overrides.
- **Generators\SecurityDefinitions** — Post-generation injection of security schemes from config into the JSON file.
- **Generators\EndpointParameterEnricher** — Replaces generic `$ref` parameters (order_by/filter_by) with endpoint-specific inline parameters using a pluggable resolver.
- **Generators\OperationSelector** — (Filtered sets) Resolves each operation's Laravel route via a `RouteResolver`, applies include-union/exclude-subtract `OperationFilter`s (unmatched operations governed by the `unmatched` policy), removes non-selected operations in place, and returns a `SelectionReport` (kept/dropped/unmatched — always surfaced).
- **Generators\ComponentTagPruner** — Runs for every set by default (opt out via `prune_unused_components => false`). Computes the transitive `$ref` closure reachable from the operations (paths + webhooks) + security requirements, then removes unreferenced components (schemas, responses, parameters, securitySchemes, …) and unused tags. Shape-agnostic ref walk (handles `$ref` at any nesting plus `discriminator.mapping`), so it never drops a genuinely-referenced schema. Security-scheme aware. Tags keep their full object (name + description) and original order. Walks an explicit closure from roots, so unreachable component cycles are still pruned.

### Filtered Documentation Sets

Emit a subset of the API as its own spec (e.g. an "integration" set of only the endpoints an API key can call), correct-by-construction. Discriminator is Laravel route middleware (ground truth), not the `security` annotation (which drifts).

- **Contracts\RouteResolver** — Interface: resolve a `ResolvableOperation` (httpMethod + path + optional controller action) to a `ResolvedRoute`, or null.
- **Routing\LaravelRouteResolver** — Default resolver. Indexes `app('router')->getRoutes()` and resolves action-first (an operation's controller action, derived from its swagger-php `_context`, maps exactly to `Route::getActionName()` — sidesteps path normalization), falling back to structural segment matching. A route/OA `{param}` matches any segment on the other side; a route's trailing optional `{param?}` may be present (matching a documented literal like `.../flat`) or absent; the most specific route (most exact literal agreements) wins so a literal route beats a wildcard one. Middleware is fully resolved via `Router::gatherRouteMiddleware()`.
- **Contracts\OperationFilter** — Interface: `matches(OperationContext): bool`.
- **Filters\MiddlewareFilter** — Default discriminator. Resolves a config alias (e.g. `auth.apikey`) or FQCN to its class via the router alias map (`getMiddleware()`, NOT the Kernel), then matches against the route's fully-resolved middleware (exact + `:params` prefix). Also **TagFilter**, **PathFilter**, **OperationIdFilter**.
- **Filters\OperationFilterFactory** — Builds filters from config descriptors (`['middleware'=>…]`, `['tag'=>…]`, …, or `['class'=>Custom::class,'args'=>[…]]`).
- **Data\ResolvableOperation / ResolvedRoute / OperationContext / SelectionReport** — Value objects threading route resolution and the selection outcome.
- Config: a documentation set's `filter` (`include`/`exclude` descriptor lists, `unmatched` policy, optional `route_prefix`), `security_override`, and `prune_unused_components` (pruning is on by default; set `false` to keep unreferenced schemas on a non-filtered set). See `src/config/openapi-docs.php` for a commented example.

### PHP Attributes (`Generators/Attributes/`)

Custom attributes applied to Data class properties to control schema output:
- `#[Example("value")]` — Explicit example value (string|int|bool|float)
- `#[Description("text")]` — Property description
- `#[Omit]` — Exclude from generated schema
- `#[GroupedCollection("key")]` — Nested grouped collection structure
- `#[ItemType("group", ?handle)]` — Class-level. Registers a Data class as a variant in a named oneOf group; handle defaults to snake_case basename
- `#[OneOfItemsFrom("group")]` — Property-level on an `array`. Emits `array<oneOf<{Variant}Item>>` where each `{Variant}Item` wraps the variant as `{ type, data }`. Abstract Data subclasses are skipped from auto-schema generation.

### Endpoint Parameter Enrichment

- **Contracts\EndpointParameterResolver** — Interface for resolving endpoint-specific parameter metadata.
- **Resolvers\DatabaseEndpointParameterResolver** — Default implementation reading from `api_resources` database tables.
- **Data\EndpointParameterData** — DTO holding orderable/filterable field lists, defaults, and optional per-field `fieldTypes` (`array<string, array{type, nullable}>`) used to emit per-operator capability hints in the filter_by description.

### Laravel Integration

- **OpenApiDocsServiceProvider** — Registers artisan commands, publishes config, binds `OpenApiGenerator` in container.
- **OpenApiDocsFacade** — Facade resolving `OpenApiGenerator::class`.
- **Config** (`src/config/openapi-docs.php`) — Supports multiple documentation sets via `documentations` key, with shared `defaults`. Covers DTO settings, output paths, scan options, security definitions, constants, endpoint parameter enrichment, and YAML generation.

### Testing

Tests use Pest with Orchestra Testbench (172 tests, 439 assertions).

| Test File | What It Covers |
|---|---|
| `tests/Unit/DtoSchemaBuilderTest.php` | DTO reflection → OA\Schema for types, defaults, enums, nullable enums, DateTime/Carbon, Optional unions, arrays, v4 collections |
| `tests/Unit/ConfigFactoryTest.php` | Deep merge — associative merge, scalar replacement, new keys |
| `tests/Unit/SecurityDefinitionsTest.php` | Security injection, deduplication, annotation precedence |
| `tests/Unit/EndpointParameterEnricherTest.php` | Parameter replacement, resource name inference, vendor extensions |
| `tests/Unit/DatabaseEndpointParameterResolverTest.php` | SQLite in-memory, two-tier lookup, missing tables |
| `tests/Unit/DataObjectTest.php` | `openapi:dto` command error handling |
| `tests/Unit/ProcessorTagSynchronizerTest.php` | Tag synchronization between OpenAPI output and processor config |
| `tests/Unit/ThunderClientGeneratorTest.php` | Thunder Client collection generation, auth, merging, sorting |
| `tests/Unit/LaravelRouteResolverTest.php` | Route resolution: action-first, path-signature fallback, param-name insensitivity, middleware gathering, disambiguation, no-match |
| `tests/Unit/MiddlewareFilterTest.php` | Middleware matching: alias→class resolution, FQCN, dual-auth, `:params`, null route, match=all |
| `tests/Unit/OperationFilterFactoryTest.php` | Descriptor → filter type, class escape hatch, error cases |
| `tests/Unit/OperationSelectorTest.php` | Include-union/exclude-subtract, unmatched policy, empty-path-item removal, SelectionReport |
| `tests/Unit/ComponentTagPrunerTest.php` | Transitive `$ref` closure (incl. cycle pruning), security-scheme pruning, tag object+order preservation |
| `tests/Integration/FullPipelineTest.php` | End-to-end: scan + DTO build + security + servers + JSON/YAML |
| `tests/Integration/FilteredDocumentationSetTest.php` | End-to-end filtered set: keep/drop by middleware, orphan-schema prune, unmatched exclusion, security_override + scheme restriction |

Test data classes live in `tests/Data/` (`TestData.php`, `ExampleData.php`, `ExampleEnum.php`, `TestDataV4.php`, `DateTimeTestData.php`, `OptionalUnionTestRequest.php`).
Test fixtures (controller with OA attributes for scanning; `RoutingController.php` for route resolution) live in `tests/Fixtures/`.

## Key Patterns

- PHP 8.1+ required; PHP 8.2 readonly properties supported via separate stub (`stubs/dto-82.stub`).
- zircote/swagger-php ^4.0: Unset values use `Generator::UNDEFINED` (not null). Always check with `=== Generator::UNDEFINED`.
- Enum handling: uses explicit `#[Example]` value when set, otherwise picks a random enum case value. Nullable enums (`?ExampleEnum`) correctly set `nullable: true` and handle null defaults.
- DateTime/Carbon handling: properties typed as `Carbon`, `CarbonImmutable`, `DateTime`, `DateTimeImmutable`, or any `DateTimeInterface` implementation are rendered as `type: "string", format: "date-time"` with ISO 8601 examples. They are not treated as nested `$ref` objects.
- Laravel Data Optional: union types containing `Spatie\LaravelData\Optional` (e.g., `string|Optional`) have Optional stripped — the remaining type is used for the schema, and the property is excluded from the `required` array. This matches Laravel Data's "sometimes" validation behavior.
- Multiple documentation sets: each key in `documentations` config overrides `defaults` via deep merge (associative arrays merged, scalars/indexed arrays replaced).
- No UI serving — this package is generation-only. Use a separate Swagger UI viewer.

## Git

- **No Co-Authored-By lines** in commit messages
- **Detailed commit messages**: Use a concise summary line, followed by a blank line and bullet points describing each meaningful change (files/areas affected and what changed). Don't be vague — call out specific renames, deletions, new files, and behavioral changes.
