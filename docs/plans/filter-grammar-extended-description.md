# Plan: Extend `filter_by` auto-generated description to cover new operators

## Context

The consumer project (`langsys2`) recently expanded `App\Traits\FilterableCollection` to support more filter operators beyond simple equality. The current operator set is:

| Form | Meaning |
|---|---|
| `field:value` | Equality (strict `===`) |
| `field:null` | Field is null |
| `field:!null` | Field is not null |
| `field:>:value` | Greater than (numeric fields only) |
| `field:<:value` | Less than (numeric fields only) |
| `field:>=:value` | Greater than or equal (numeric fields only) |
| `field:<=:value` | Less than or equal (numeric fields only) |

Multiple filters via repeated query params (`filter_by[]=…&filter_by[]=…`).

## Problem

`EndpointParameterEnricher::buildFilterByDescription` (src/Generators/EndpointParameterEnricher.php, around line 377) currently produces:

> "Filter results by field values. Supports single filter (filter_by=field:value) or multiple filters (filter_by[]=field1:value&filter_by[]=field2:value)."

…followed by the resource-specific `Filterable fields:` and `Default filters:` lines. This text only documents equality. With `endpoint_parameters.enabled => true` (which langsys2 uses), this description **replaces** the hardcoded `$ref` description on every list endpoint that has an `api_resources` row — so users never see the extended grammar.

The hardcoded fallback in the consumer (`app/Http/Controllers/API/Controller.php` in langsys2) has already been updated to mention all 7 operators, but that fallback only shows for endpoints with no resource match. Most real endpoints have a match and therefore show the library's text.

## Phase 1 — minimal: update the grammar text

**File:** `src/Generators/EndpointParameterEnricher.php`
**Method:** `buildFilterByDescription`

Replace the lead-in text with one that describes all 7 operators. Suggested wording:

```
Filter results by field values.

Operators:
- field:value          equality (strict type match)
- field:null           field is null
- field:!null          field is not null
- field:<op>:value     comparison (op ∈ >, <, >=, <=) — numeric fields only

Multiple filters: filter_by[]=field1:value&filter_by[]=field2:value
```

…followed by the existing `**Filterable fields:**` and `**Default filters:**` lines (no change to that part).

Update `buildFilterByExample` if needed — current default of `{firstField}:value` is fine; no change required.

Update test fixtures under `tests/` that assert on the old wording. Likely at least one snapshot of the generated description.

This phase is text-only and zero-risk. Land it first.

## Phase 2 — enhancement: per-field operator support

**Goal:** the description should tell users which fields support `null`/`!null` and which support comparison, instead of leaving them to guess or hit a silently-dropped filter.

Operator support is derived from the **Resource constructor's typed properties** in the consumer:

- `null`/`!null` valid iff the property is declared nullable (`?string`, `?int`, etc.)
- `>`/`<`/`>=`/`<=` valid iff the property's type is `int` or `float`

The consumer (`langsys2`) already does this reflection in `App\Services\ApiResourceService::getFieldTypesForResource`. The library needs the same data shape:

```php
array<string, array{type: string, nullable: bool}>
```

### Suggested shape changes

1. Add an optional property to `Data/EndpointParameterData`:
   ```php
   /** @var array<string, array{type: string, nullable: bool}> */
   public array $fieldTypes = [];
   ```
   Default `[]` keeps full back-compat — older resolvers don't populate it, description falls back to Phase-1 wording.

2. Allow consumers to populate `fieldTypes` without forking the library. Two options:

   - **Option A (preferred, minimal):** Make `DatabaseEndpointParameterResolver` accept an optional callable from config, e.g. `endpoint_parameters.field_types_resolver => fn (string $resourceName): array`. The resolver invokes it after `findResource`. If callable returns non-empty, attach to `EndpointParameterData->fieldTypes`. No reflection inside the library itself — keeps it framework-agnostic.

   - **Option B:** Add a `resource_namespace` config (default `App\Http\Resources`) plus a `ReflectionFieldTypeResolver` helper that reflects on `"{namespace}\\{resourceName}"::__construct` parameters. Less config burden for consumers, but bakes Laravel-Data / typed-DTO conventions into the library. langsys2 fits that mold; other consumers might not.

   Option A is more flexible; Option B is more turnkey. Either works.

3. Extend `buildFilterByDescription` to emit two additional lines when `$fieldTypes` is non-empty:

   ```
   **Supports null check (field:null / field:!null):** `description`, `last_used_at`
   **Supports comparison (>, <, >=, <=):** `amount`, `count`
   ```

   Derived by filtering `filterableFields` against `fieldTypes`:
   - Nullable list: `$fieldTypes[$f]['nullable'] === true`
   - Comparison list: `in_array($fieldTypes[$f]['type'], ['int', 'float'], true)`

   Omit a section entirely if its filtered list is empty. If `fieldTypes` is `[]` (no resolver configured), behave as Phase 1.

### Edge cases / constraints

- **No type info available** → description falls back to listing fields without per-operator capability hints. Don't crash, don't omit the field list.
- **Field present in `filterableFields` but absent from `fieldTypes`** → list the field in `Filterable fields:` but exclude from the nullable/comparison sub-lists. (A misconfigured filterable_fields row pointing at a non-existent Resource property would otherwise mislead users.)
- **`fieldTypes` shape mismatch** → fail loudly during generation, not silently in production docs. Add a typed cast or schema check when reading.
- **Caching** — `EndpointParameterEnricher` runs once per `php artisan openapi:generate`; no need for in-process caching of reflection.

### Tests to add

- `EndpointParameterEnricher` description includes the new operator block (Phase 1).
- `EndpointParameterEnricher` description includes the "Supports null check" / "Supports comparison" lines when `fieldTypes` populated (Phase 2).
- Description omits the sub-lines when `fieldTypes` is empty.
- Resolver respects/passes through the `field_types_resolver` callable from config (Option A) — fixture with a closure that returns a fixed array.

## Out of scope

- Adding new operators to the grammar (handled in the consumer).
- Reflection inside the library on consumer Resource classes (Option B above is a possible future direction but should not block Phase 1).
- Updating consumer hardcoded `@OA\Parameter` text in `langsys2/app/Http/Controllers/API/Controller.php` — already done.

## Acceptance

After Phase 1: every list endpoint's `filter_by` description in `api-docs.json` lists all 7 operators, not just equality.

After Phase 2 (langsys2 wires up `field_types_resolver` to point at `App\Services\ApiResourceService::getFieldTypesForResource`): the description additionally calls out per-field nullable/comparison support, derived from the actual Resource constructor signatures.
