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
6. `buildAndMergeDtoSchemas()` — Build DTO schemas via `DtoSchemaBuilder` and merge into OpenAPI model (annotation-defined schemas take precedence). Also merges `components.responses.{Name}` for every discovered error DTO (annotation-defined responses take precedence)
7. `attachOperationErrors()` — (When errors exist) Attach declared (`#[Throws]`) and implied (`implied_errors`) error responses to each operation: one `$ref` per status, a `code`-discriminated `oneOf` on the `error` property when a status is shared, hand-written responses win. Runs before pruning so the refs it adds are in the closure
8. `enrichEndpointParameters()` — Replace generic `$ref` parameters with endpoint-specific inline parameters
9. `scopeErrorsToOperations()` — (When errors exist) Remove error components (details, Body, Response, `components.responses`) no operation references — **regardless of `prune_unused_components`** — and add the `ErrorCode` enum listing exactly the errors that remain. Reachability is `ComponentTagPruner::reachableRefs()` from the operations; with pruning off, every kept non-error component is also a root so no kept schema dangles
10. `pruneComponentsAndTags()` — Remove components/tags outside the transitive `$ref` closure of the surviving operations. On by default so no document ships unused schemas; opt out per non-filtered set with `prune_unused_components => false` (filtered sets always prune). The error-code enum schema (`errors.code_schema`) is seeded as an extra root so the error-codes reference page survives
11. `populateServers()` — Add server entries from config
12. `applyInfoOverride()` — (Per-set `info`) Deep-merge a documentation set's `info` fields (title/description/version/contact/license) over the scanned `@OA\Info`; unspecified fields fall back to the annotation
13. `validateReferences()` — (Opt-in `validate_refs`) Detect referenced-but-undefined `$ref`s; `warn` records them (console), `strict` throws before writing so a broken spec is never saved
14. `saveJson()` — Save OpenAPI model as JSON
15. `injectSecurity()` — Inject security definitions from config into JSON (restricted to the override's schemes when `security_override` is set)
16. `makeYamlCopy()` — Optionally convert JSON to YAML

Note: `generate()` returns the fully-assembled `OA\OpenApi` tree, so every step after step 3 mutates that in-memory model. Filtering is therefore post-scan (the discriminator, route middleware, is not present in the scanned annotations). Clean output is guaranteed by the prune step, not by a reference-driven build: `buildAndMergeDtoSchemas()` builds all DTOs, then `pruneComponentsAndTags()` removes everything outside the reference closure. This build-all-then-prune order is deliberate — it can never emit a dangling `$ref` (pruning only removes unreachable components), whereas a reference-driven "build only the closure" would risk under-building. A lazy closure build is a possible future perf optimization, but only with an added `$ref`-resolution validation pass; it is not needed for correctness.

### Key Classes (under `Langsys\OpenApiDocsGenerator`)

- **Generators\OpenApiGenerator** — Main orchestrator. Runs the pipeline above.
- **Generators\GeneratorFactory** — Factory that wires all dependencies from config and returns an `OpenApiGenerator`.
- **Generators\DtoSchemaBuilder** — Core class. Scans a directory for Spatie `Data` subclasses, reflects on their properties, and builds `OA\Schema` objects directly in memory. Handles enums (including nullable enums), nested objects, collections, grouped collections, arrays, DateTime/Carbon (as `string` with `date-time` format), and primitives. Strips `Spatie\LaravelData\Optional` from union types and marks those properties as not required. Auto-generates Response/PaginatedResponse/ListResponse wrappers for Resource DTOs. **API errors**: every non-abstract subclass of `errors.base_class` is an error (see **Error Class Contract**) — reads `CODE`/`MESSAGE`/`STATUS` via `ErrorContract`, builds the `{Name}` details schema (non-`#[EnvelopeField]` props), then the `{Name}Body` error object and `{Name}Response` envelope via `ErrorEnvelope`. It does not emit the `ErrorCode` enum: `buildErrorCodeSchema(array $definitions)` is public and the generator calls it with only the referenced errors. Throws `OpenApiDocsException` on a contract violation or a duplicate code. Exposes `getErrorDefinitions()` (`Data\ErrorDefinition[]`), `getErrorCodeSchemaName()`, `getErrorEnvelope()` and `getErrorContract()`. `@var array<string, T>` docblocks on `array` props become `object` + `additionalProperties`.
- **Generators\ErrorContract** — Reads and validates an error class's identity (see **Error Class Contract**): `isErrorClass()` (non-abstract subclass of `errors.base_class`) and `read()` → `{code, message, status}`. Validates `errors.base_class` exists and extends Spatie `Data`. Owned by `DtoSchemaBuilder` (`getErrorContract()`); the generator passes it to the attacher so failures can say whether a class is not an error class or was never scanned.
- **Generators\ErrorEnvelope** — The one definition of the error response shape: `{ status, data, error: { message, code, details, ...#[EnvelopeField] props } }`. Names from `errors.response_fields` / `errors.error_fields` (`null` omits; `error` and `code` can't be null; unknown keys, duplicate names and the removed `errors.fields` key throw). Builds `{Name}Body` (`message` example = `MESSAGE`, `code` single-value enum, `details` `$ref`; throws if an `#[EnvelopeField]` reuses an error-object name), `{Name}Response`, and `sharedStatusSchema()` for the attacher, which puts the `oneOf` + code discriminator on the `error` property over the Body schemas because OAS 3.0 discriminators need a top-level property of each variant. Schemas stay open (no `additionalProperties: false`) so undocumented fields like app debug output validate. The builder owns the instance (`getErrorEnvelope()`) and the generator passes it to the attacher, so attached responses always match the schemas they reference.
- **Generators\OperationErrorAttacher** — Attaches error responses to operations. Sources, unioned and deduped by class: `#[Throws]` on the action (or its controller class), `implied_errors.middleware` (matched via `MiddlewareFilter` against the route's fully-resolved middleware), two structural rules (`implied_errors.validation` when the action takes a Spatie `Data` parameter; `implied_errors.not_found` when the route has a bound `{param}` — `not_found_binding` 'model' (default, implicit model binding or `Route::bind()`) or 'any'), and app-supplied `implied_errors.rules`. Grouped by status: one error → `$ref` to `components.responses.{Name}`; several → inline response from `ErrorEnvelope::sharedStatusSchema()`, a `oneOf` of their `{Name}Body` schemas on the `error` property discriminated on the configured code field. Hand-written responses for a status win. Throws `OpenApiDocsException` when a declared class is not a concrete subclass of `errors.base_class` or was never scanned (the precise message needs the `ErrorContract`, which the generator passes). Inert without `#[Throws]`/`implied_errors`; without a `RouteResolver` only `#[Throws]` and the validation rule apply.
- **Contracts\ImpliedErrorRule** — App-owned rule: `errorsFor(OperationContext, ?ReflectionMethod): array<class-string>`. The escape hatch for conventions the framework cannot prove (an in-body authorization call, a permission registry). The library deliberately ships no implementation — a heuristic over an implementation idiom belongs with the app that owns it, where a refactor that breaks it is visible. Built by **Generators\ImpliedErrorRuleFactory** from a class name, `['class' => …, 'args' => […]]`, or an instance; mirrors `OperationFilterFactory`'s `class` escape hatch.
- **Support\OperationAction** — Derives `"FQCN@method"` from an operation's swagger-php `_context` and reflects it. Shared by `OperationSelector` (route resolution) and `OperationErrorAttacher` (`#[Throws]` lookup).
- **Generators\ExampleGenerator** — Produces example values using Faker, with configurable attribute mapping (property name patterns → Faker methods) and custom function overrides.
- **Generators\ConfigFactory** — Deep-merges `defaults` config with per-documentation overrides.
- **Generators\SecurityDefinitions** — Post-generation injection of security schemes from config into the JSON file.
- **Generators\EndpointParameterEnricher** — Replaces generic `$ref` parameters (order_by/filter_by) with endpoint-specific inline parameters using a pluggable resolver.
- **Generators\OperationSelector** — (Filtered sets) Resolves each operation's Laravel route via a `RouteResolver`, applies include-union/exclude-subtract `OperationFilter`s (unmatched operations governed by the `unmatched` policy), removes non-selected operations in place, and returns a `SelectionReport` (kept/dropped/unmatched — always surfaced).
- **Generators\ComponentTagPruner** — Runs for every set by default (opt out via `prune_unused_components => false`). Computes the transitive `$ref` closure reachable from the operations (paths + webhooks) + security requirements, then removes unreferenced components (schemas, responses, parameters, securitySchemes, …) and unused tags. Shape-agnostic ref walk (handles `$ref` at any nesting plus `discriminator.mapping`), so it never drops a genuinely-referenced schema. Security-scheme aware. Tags keep their full object (name + description) and original order. Walks an explicit closure from roots, so unreachable component cycles are still pruned. `reachableRefs()` and `componentRefs()` are public so the generator scopes error documentation with the same closure.
- **Generators\ReferenceValidator** — Opt-in (`validate_refs => 'warn'|'strict'`, off by default). Finds referenced-but-undefined local `$ref`s (the pruner never *drops* something referenced, but can't catch a `$ref` to a component that was never defined — e.g. a hand-written `@OA\Parameter(ref=…)` with no definition block, undetected because the scan runs with swagger-php validation off). Shape-agnostic collection (`$ref` at any nesting + `discriminator.mapping`); resolves each local ref as a JSON pointer; reports `['ref' => …, 'location' => 'METHOD /path']`. `strict` throws (surfaced by `OpenApiGenerator::validateReferences()` before save); `warn` exposes them via `getUnresolvedReferences()` for the console command.

### Filtered Documentation Sets

Emit a subset of the API as its own spec (e.g. an "integration" set of only the endpoints an API key can call), correct-by-construction. Discriminator is Laravel route middleware (ground truth), not the `security` annotation (which drifts).

- **Contracts\RouteResolver** — Interface: resolve a `ResolvableOperation` (httpMethod + path + optional controller action) to a `ResolvedRoute`, or null.
- **Routing\LaravelRouteResolver** — Default resolver. Indexes `app('router')->getRoutes()` and resolves action-first (an operation's controller action, derived from its swagger-php `_context`, maps exactly to `Route::getActionName()` — sidesteps path normalization), falling back to structural segment matching. A route/OA `{param}` matches any segment on the other side; a route's trailing optional `{param?}` may be present (matching a documented literal like `.../flat`) or absent; the most specific route (most exact literal agreements) wins so a literal route beats a wildcard one. Middleware is fully resolved via `Router::gatherRouteMiddleware()`.
- **Contracts\OperationFilter** — Interface: `matches(OperationContext): bool`.
- **Filters\MiddlewareFilter** — Default discriminator. Resolves a config alias (e.g. `auth.apikey`) or FQCN to its class via the router alias map (`getMiddleware()`, NOT the Kernel), then matches against the route's fully-resolved middleware (exact + `:params` prefix). Also **TagFilter**, **PathFilter**, **OperationIdFilter**.
- **Filters\OperationFilterFactory** — Builds filters from config descriptors (`['middleware'=>…]`, `['tag'=>…]`, …, or `['class'=>Custom::class,'args'=>[…]]`).
- **Data\ResolvableOperation / ResolvedRoute / OperationContext / SelectionReport** — Value objects threading route resolution and the selection outcome.
- Config: a documentation set's `filter` (`include`/`exclude` descriptor lists, `unmatched` policy, optional `route_prefix`), `security_override`, `info` (per-set title/description/… deep-merged over the scanned `@OA\Info`), and `prune_unused_components` (pruning is on by default; set `false` to keep unreferenced schemas on a non-filtered set). See `src/config/openapi-docs.php` for a commented example.

### PHP Attributes (`Generators/Attributes/`)

Custom attributes applied to Data class properties to control schema output:
- `#[Example("value")]` — Explicit example value (string|int|bool|float)
- `#[Description("text")]` — Property description
- `#[Omit]` — Exclude from generated schema
- `#[GroupedCollection("key")]` — Nested grouped collection structure
- `#[ItemType("group", ?handle)]` — Class-level. Registers a Data class as a variant in a named oneOf group; handle defaults to snake_case basename
- `#[OneOfItemsFrom("group")]` — Property-level on an `array`. Emits `array<oneOf<{Variant}Item>>` where each `{Variant}Item` wraps the variant as `{ type, data }`. Abstract Data subclasses are skipped from auto-schema generation.
- `#[EnvelopeField]` — Property-level on an error DTO: emit at the top level of the `error` object instead of under `details`.
- `#[Throws(...classes)]` — Method-level on controller actions, or class-level to cover every action of a controller: the errors it can return.
- Error identity (code, message, status) is **not** an attribute: see **Error Class Contract**. `#[Description]` is property-level only; a class-level one on an error class fails generation.

### Error Class Contract

API errors are Spatie Data classes identified by **class constants**, not attributes (there is no `#[ErrorCode]` or `#[HttpStatus]`). Discovery: every non-abstract subclass of `errors.base_class` (a class name or a list; `null` disables errors) found in the scanned directories (`paths.annotations` plus `errors.paths`). Read and validated by `Generators\ErrorContract`.

```php
class NotFoundError extends ApiError            // abstract ApiError extends Spatie Data
{
    public const string CODE = 'not_found';
    public const string MESSAGE = 'Resource not found';
    public const HttpCode STATUS = HttpCode::NOT_FOUND;   // int, or int-backed enum
}

class BreakerNotFoundError extends NotFoundError  // a specific failure mode
{
    public const string CODE = 'breaker_not_found';
    public const string MESSAGE = 'No breaker row for that fingerprint';
    // STATUS inherited from NotFoundError
}
```
(Typed constants need PHP 8.3; untyped `public const CODE = …` works on 8.1. Test fixtures use untyped.)

- `CODE` and `MESSAGE` are non-empty strings that each concrete error class **declares itself**: `ReflectionClassConstant::getDeclaringClass()` must be the class. Constants inherit silently, so this is the guard that stops two failure modes sharing a code.
- `STATUS` is an int or an int-backed enum (read via `->value`), HTTP 100–599, and may be inherited from any parent, abstract or concrete. That is the point of descendants.
- `MESSAGE` is also the documentation text: `components.responses.{Name}.description`, the `{Name}Body.message` example, and each `ErrorCode` enum line. A class-level `#[Description]` on an error class or its parents is rejected, not ignored, so the same sentence is never written twice.
- Hard failures (`OpenApiDocsException`, before writing): a missing or inherited `CODE`/`MESSAGE`, an empty value, a duplicate `CODE`, an unresolvable or out-of-range `STATUS`, a class-level `#[Description]`, an `#[EnvelopeField]` reusing an error-object field name, and a non-existent or non-Data `errors.base_class`.
- Unchanged by the contract: `#[Throws]`, `#[EnvelopeField]`, property-level `#[Description]`, `implied_errors`, rules, and the envelope shape (`ErrorEnvelope`).
- **Scoping.** Only errors the documentation set's operations reference are documented (`OpenApiGenerator::scopeErrorsToOperations()`), independent of `prune_unused_components`. So internal errors, such as those of super-admin-only routes, never leak into a set's spec, and each set's `ErrorCode` list is honest. With pruning off, kept non-error components also count as roots, so a kept schema that references an error keeps it.

### Endpoint Parameter Enrichment

- **Contracts\EndpointParameterResolver** — Interface for resolving endpoint-specific parameter metadata.
- **Resolvers\DatabaseEndpointParameterResolver** — Default implementation reading from `api_resources` database tables.
- **Data\EndpointParameterData** — DTO holding orderable/filterable field lists, defaults, and optional per-field `fieldTypes` (`array<string, array{type, nullable}>`) used to emit per-operator capability hints in the filter_by description.

### Laravel Integration

- **OpenApiDocsServiceProvider** — Registers artisan commands, publishes config, binds `OpenApiGenerator` in container.
- **OpenApiDocsFacade** — Facade resolving `OpenApiGenerator::class`.
- **Config** (`src/config/openapi-docs.php`) — Supports multiple documentation sets via `documentations` key, with shared `defaults`. Covers DTO settings, output paths, scan options, security definitions, constants, endpoint parameter enrichment, and YAML generation.

### Testing

Tests use Pest with Orchestra Testbench (258 tests, 719 assertions).

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
| `tests/Unit/ErrorAttributesTest.php` | `Throws` (method + class, variadic), `EnvelopeField`, attribute targets (no class-level `Description`), `ErrorCode`/`HttpStatus` removed |
| `tests/Unit/ErrorSchemaBuilderTest.php` | Base-class discovery, constant reading (int/enum `STATUS`, inheritance from abstract and concrete parents), contract violations generated into temp dirs (missing/inherited/empty `CODE`/`MESSAGE`, `STATUS`, class `Description`, duplicate code, envelope-field collision), base-class validation, details/Body/Response shapes, `buildErrorCodeSchema()` subsets, field config + validation, `errors.paths` |
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
| `tests/Unit/ErrorAttributesTest.php` | `Throws` (method + class, variadic), `EnvelopeField`, attribute targets (no class-level `Description`), `ErrorCode`/`HttpStatus` removed |
| `tests/Unit/ErrorSchemaBuilderTest.php` | Error discovery, inherited `HttpStatus`, details/Body/Response/`ErrorCode` shapes, response- and error-level field config, config validation (removed `fields`, required names, unknown keys, duplicate names, envelope-field collisions), `errors.paths`, missing-status failure |
| `tests/Integration/ErrorResponsesTest.php` | `components.responses.{Name}` (`MESSAGE` description, `x-http-status`), hand-written `ref` resolves under strict validation, `ErrorCode` lists only referenced codes, unreferenced error components removed even with pruning off |
| `tests/Unit/OperationErrorAttacherTest.php` | `$ref` vs `error`-level `oneOf`+discriminator (incl. configured field names), annotation precedence, middleware/validation/not-found rules, dedupe, class-level `Throws`, no-resolver and no-context cases, both failure messages, custom `ImpliedErrorRule` descriptors + contract errors |
| `tests/Integration/OperationErrorAttachmentTest.php` | End-to-end attachment: single/shared status, hand-written precedence, pruning keeps attached refs, middleware + structural rules, no-resolver, error scoping with pruning off (honest code list, ordinary DTOs kept, a kept schema keeps the error it references) |
| `tests/Unit/ReferenceValidatorTest.php` | Unresolved-`$ref` detection: resolved vs dangling, nested closure, discriminator mapping, external-ref skip, location formatting |
| `tests/Integration/FullPipelineTest.php` | End-to-end: scan + DTO build + security + servers + JSON/YAML |
| `tests/Integration/FilteredDocumentationSetTest.php` | End-to-end filtered set: keep/drop by middleware, orphan-schema prune, unmatched exclusion, security_override + scheme restriction |
| `tests/Integration/ReferenceValidationTest.php` | `validate_refs` off/warn/strict pipeline behavior (report vs abort-before-write) |
| `tests/Integration/GenerateCommandExitCodeTest.php` | `openapi:generate` exit code — non-zero if any set fails (strict abort), 0 on success; `--all` fails-if-any while successful sets still write |
| `tests/Integration/InfoOverrideTest.php` | Per-set `info` override — replaces title/description, unspecified fields fall back to `@OA\Info`, nested contact deep-merge |

Test data classes live in `tests/Data/` (`TestData.php`, `ExampleData.php`, `ExampleEnum.php`, `TestDataV4.php`, `DateTimeTestData.php`, `OptionalUnionTestRequest.php`).
Test fixtures (controller with OA attributes for scanning; `RoutingController.php` for route resolution) live in `tests/Fixtures/`. `tests/ErrorFixtures/` holds the `ApiError` base, an `HttpCode` enum, error classes covering int and enum `STATUS` and inheritance from abstract and concrete parents, and a controller referencing one (contract-violation classes are generated into temp dirs by `ErrorSchemaBuilderTest`); `tests/ErrorOperationFixtures/` holds the `#[Throws]` controllers, a second 422 error, a bound model, a Data request, and a `SourceScanRule`/`NoopRule` pair for the custom-rule tests, plus an annotation schema that references an error body for the dangling-ref guard; its error DTOs come from `tests/ErrorFixtures/` via `errors.paths`. `tests/DanglingFixtures/` holds a controller with a deliberately undefined `$ref` (kept out of `tests/Fixtures/` so it doesn't pollute other scan-based tests).

## Key Patterns

- PHP 8.1+ required; PHP 8.2 readonly properties supported via separate stub (`stubs/dto-82.stub`).
- zircote/swagger-php ^4.0: Unset values use `Generator::UNDEFINED` (not null). Always check with `=== Generator::UNDEFINED`.
- Enum handling: uses explicit `#[Example]` value when set, otherwise picks a random enum case value. Nullable enums (`?ExampleEnum`) correctly set `nullable: true` and handle null defaults.
- DateTime/Carbon handling: properties typed as `Carbon`, `CarbonImmutable`, `DateTime`, `DateTimeImmutable`, or any `DateTimeInterface` implementation are rendered as `type: "string", format: "date-time"` with ISO 8601 examples. They are not treated as nested `$ref` objects.
- Laravel Data Optional: union types containing `Spatie\LaravelData\Optional` (e.g., `string|Optional`) have Optional stripped — the remaining type is used for the schema, and the property is excluded from the `required` array. This matches Laravel Data's "sometimes" validation behavior.
- Multiple documentation sets: each key in `documentations` config overrides `defaults` via deep merge (associative arrays merged, scalars/indexed arrays replaced).
- No UI serving — this package is generation-only. Use a separate Swagger UI viewer.
- `openapi:generate` exit code: returns non-zero (`self::FAILURE`) if any documentation set fails to generate (e.g. a `validate_refs => 'strict'` abort), so it works as a CI/deploy gate (`if artisan openapi:generate; then …`). In an `--all` run, successful sets still write their output; only the failing set aborts-before-write, and the overall exit is non-zero.

## Git

- **No Co-Authored-By lines** in commit messages
- **Detailed commit messages**: Use a concise summary line, followed by a blank line and bullet points describing each meaningful change (files/areas affected and what changed). Don't be vague — call out specific renames, deletions, new files, and behavioral changes.
