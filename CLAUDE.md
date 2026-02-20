# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel package (`langsys/swagger-auto-generator`) that bridges Spatie Laravel Data and L5-Swagger to automatically generate OpenAPI/Swagger schema annotations from Data Transfer Objects. Alpha status.

## Commands

```bash
# Install dependencies
composer install

# Run tests (Pest framework via Orchestra Testbench)
./vendor/bin/pest

# Run a single test file
./vendor/bin/pest tests/Unit/DataSwaggerGenerateTest.php

# Run a specific test by name
./vendor/bin/pest --filter="generates annotations"
```

There are no composer scripts defined — use `./vendor/bin/pest` directly.

## Architecture

### Generation Pipeline

`SwaggerSchemaGenerator` is the main orchestrator. It scans a directory for classes extending Spatie's `Data`, then for each class creates a `Schema` which reflects on its public properties/constructor params to produce `Property` objects. Each Property generates an OpenAPI annotation line. The final output is written as a PHP file containing OA annotation comment blocks.

### Key Classes (all under `Langsys\SwaggerAutoGenerator\Generators\Swagger`)

- **SwaggerSchemaGenerator** — Entry point. Scans directory, identifies Data subclasses, delegates to Schema. Instantiated with `(dataPath, outputPath, namespace)`.
- **Schema** — Reflects on a single Data class. Detects if it's a "resource" (suffix-based) and auto-generates Request/Response/PaginatedResponse variants. Uses `Property` for each field.
- **Property** — Generates a single `@OA\Property(...)` annotation. Handles basic types, enums, nested objects, arrays, defaults, and nullable types.
- **ExampleGenerator** — Produces example values using Faker, with a configurable attribute mapper (property name patterns → Faker methods) and custom function overrides.

### PHP Attributes (`Generators/Swagger/Attributes/`)

Custom attributes applied to Data class properties to control schema output:
- `#[Example("value")]` — Explicit example value
- `#[Description("text")]` — Property description
- `#[Omit]` — Exclude from generated schema
- `#[GroupedCollection]` — Nested grouped collection structure

### Laravel Integration

- **Service Provider** (`SwaggerAutoGeneratorServiceProvider`) — Registers artisan commands, publishes config.
- **Config** (`src/config/langsys-generator.php`) — Paths for data objects and output, faker attribute mapper, custom functions, pagination fields.
- **Commands**: `data-swagger:generate` (schema generation with `--cascade`, `--minified`, `--docs`, `--ts` options) and `data-swagger:dto --model=` (generates Data class from Eloquent model).

### Testing

Tests use Pest with Orchestra Testbench. The main generation test (`tests/Unit/DataSwaggerGenerateTest.php`) instantiates `SwaggerSchemaGenerator` pointing at `tests/Data/` and asserts the generated output matches `tests/Output/ExpectedSchemas.php` exactly. When changing generation logic, update `ExpectedSchemas.php` to match expected output.

Test data classes live in `tests/Data/` (e.g., `TestData.php`, `ExampleData.php`, `ExampleEnum.php`).

## Key Patterns

- PHP 8.1+ required; PHP 8.2 readonly properties supported via separate stub (`stubs/dto-82.stub`).
- Pretty-printed output is the default; `--minified` flag for compact output. The `PrettyPrints` trait and `PrintsSwagger` interface control formatting.
- Enum handling: uses explicit `#[Example]` value when set, otherwise picks a random enum case value.
