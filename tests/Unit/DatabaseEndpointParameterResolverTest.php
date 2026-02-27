<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Langsys\OpenApiDocsGenerator\Resolvers\DatabaseEndpointParameterResolver;

beforeEach(function () {
    // Create the required tables in SQLite in-memory
    Schema::create('api_resources', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('endpoint')->nullable();
        $table->timestamps();
    });

    Schema::create('resource_orderable_fields', function ($table) {
        $table->id();
        $table->unsignedBigInteger('api_resource_id');
        $table->string('field_name');
    });

    Schema::create('resource_default_order_entries', function ($table) {
        $table->id();
        $table->unsignedBigInteger('resource_orderable_field_id');
        $table->string('direction')->default('asc');
        $table->integer('sort_order')->default(0);
    });

    Schema::create('resource_filterable_fields', function ($table) {
        $table->id();
        $table->unsignedBigInteger('api_resource_id');
        $table->string('field_name');
    });

    Schema::create('resource_default_filters', function ($table) {
        $table->id();
        $table->unsignedBigInteger('resource_filterable_field_id');
        $table->string('filter_value');
    });

    $this->resolver = new DatabaseEndpointParameterResolver();
});

afterEach(function () {
    Schema::dropIfExists('resource_default_filters');
    Schema::dropIfExists('resource_filterable_fields');
    Schema::dropIfExists('resource_default_order_entries');
    Schema::dropIfExists('resource_orderable_fields');
    Schema::dropIfExists('api_resources');
});

test('it returns null when no matching resource exists', function () {
    $result = $this->resolver->resolve('api/projects', 'NonExistentResource');

    expect($result)->toBeNull();
});

test('it resolves with endpoint-specific match (tier 1)', function () {
    $resourceId = DB::table('api_resources')->insertGetId([
        'name' => 'ProjectResource',
        'endpoint' => 'api/projects',
    ]);

    DB::table('resource_orderable_fields')->insert([
        ['api_resource_id' => $resourceId, 'field_name' => 'title'],
        ['api_resource_id' => $resourceId, 'field_name' => 'created_at'],
    ]);

    $result = $this->resolver->resolve('api/projects', 'ProjectResource');

    expect($result)->not->toBeNull()
        ->and($result->orderableFields)->toBe(['title', 'created_at']);
});

test('it falls back to resource-wide default (tier 2) when no endpoint match', function () {
    $resourceId = DB::table('api_resources')->insertGetId([
        'name' => 'ProjectResource',
        'endpoint' => null,
    ]);

    DB::table('resource_orderable_fields')->insert([
        ['api_resource_id' => $resourceId, 'field_name' => 'name'],
    ]);

    $result = $this->resolver->resolve('api/projects', 'ProjectResource');

    expect($result)->not->toBeNull()
        ->and($result->orderableFields)->toBe(['name']);
});

test('it prefers endpoint-specific match over resource-wide default', function () {
    // Resource-wide default
    $defaultId = DB::table('api_resources')->insertGetId([
        'name' => 'ProjectResource',
        'endpoint' => null,
    ]);
    DB::table('resource_orderable_fields')->insert([
        ['api_resource_id' => $defaultId, 'field_name' => 'default_field'],
    ]);

    // Endpoint-specific
    $specificId = DB::table('api_resources')->insertGetId([
        'name' => 'ProjectResource',
        'endpoint' => 'api/projects',
    ]);
    DB::table('resource_orderable_fields')->insert([
        ['api_resource_id' => $specificId, 'field_name' => 'specific_field'],
    ]);

    $result = $this->resolver->resolve('api/projects', 'ProjectResource');

    expect($result->orderableFields)->toBe(['specific_field']);
});

test('it resolves orderable fields', function () {
    $resourceId = DB::table('api_resources')->insertGetId([
        'name' => 'UserResource',
        'endpoint' => null,
    ]);

    DB::table('resource_orderable_fields')->insert([
        ['api_resource_id' => $resourceId, 'field_name' => 'name'],
        ['api_resource_id' => $resourceId, 'field_name' => 'email'],
        ['api_resource_id' => $resourceId, 'field_name' => 'created_at'],
    ]);

    $result = $this->resolver->resolve('api/users', 'UserResource');

    expect($result->orderableFields)->toBe(['name', 'email', 'created_at']);
});

test('it resolves default order entries', function () {
    $resourceId = DB::table('api_resources')->insertGetId([
        'name' => 'UserResource',
        'endpoint' => null,
    ]);

    $field1Id = DB::table('resource_orderable_fields')->insertGetId([
        'api_resource_id' => $resourceId,
        'field_name' => 'name',
    ]);

    $field2Id = DB::table('resource_orderable_fields')->insertGetId([
        'api_resource_id' => $resourceId,
        'field_name' => 'created_at',
    ]);

    DB::table('resource_default_order_entries')->insert([
        ['resource_orderable_field_id' => $field2Id, 'direction' => 'desc', 'sort_order' => 1],
        ['resource_orderable_field_id' => $field1Id, 'direction' => 'asc', 'sort_order' => 2],
    ]);

    $result = $this->resolver->resolve('api/users', 'UserResource');

    expect($result->defaultOrder)->toBe([
        ['created_at', 'desc'],
        ['name', 'asc'],
    ]);
});

test('it resolves filterable fields', function () {
    $resourceId = DB::table('api_resources')->insertGetId([
        'name' => 'UserResource',
        'endpoint' => null,
    ]);

    DB::table('resource_filterable_fields')->insert([
        ['api_resource_id' => $resourceId, 'field_name' => 'status'],
        ['api_resource_id' => $resourceId, 'field_name' => 'role'],
    ]);

    $result = $this->resolver->resolve('api/users', 'UserResource');

    expect($result->filterableFields)->toBe(['status', 'role']);
});

test('it resolves default filters', function () {
    $resourceId = DB::table('api_resources')->insertGetId([
        'name' => 'UserResource',
        'endpoint' => null,
    ]);

    $fieldId = DB::table('resource_filterable_fields')->insertGetId([
        'api_resource_id' => $resourceId,
        'field_name' => 'status',
    ]);

    DB::table('resource_default_filters')->insert([
        ['resource_filterable_field_id' => $fieldId, 'filter_value' => 'active'],
    ]);

    $result = $this->resolver->resolve('api/users', 'UserResource');

    expect($result->defaultFilters)->toBe([
        ['status', 'active'],
    ]);
});

test('it returns empty arrays when resource has no fields configured', function () {
    DB::table('api_resources')->insert([
        'name' => 'EmptyResource',
        'endpoint' => null,
    ]);

    $result = $this->resolver->resolve('api/empty', 'EmptyResource');

    expect($result)->not->toBeNull()
        ->and($result->orderableFields)->toBe([])
        ->and($result->defaultOrder)->toBe([])
        ->and($result->filterableFields)->toBe([])
        ->and($result->defaultFilters)->toBe([]);
});
