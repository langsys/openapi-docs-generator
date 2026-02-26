<?php

namespace Langsys\OpenApiDocsGenerator\Resolvers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Langsys\OpenApiDocsGenerator\Contracts\EndpointParameterResolver;
use Langsys\OpenApiDocsGenerator\Data\EndpointParameterData;

class DatabaseEndpointParameterResolver implements EndpointParameterResolver
{
    /**
     * Cached result of whether the api_resources table exists.
     * null = not yet checked, true/false = cached result.
     */
    private ?bool $tablesExist = null;

    /**
     * Whether we have already logged a warning about missing tables.
     */
    private bool $warningLogged = false;

    /**
     * Resolve endpoint parameter data from the database.
     *
     * Uses a two-tier lookup:
     *   1. Query for (name, endpoint) — endpoint-specific config
     *   2. Fall back to (name, null) — resource-wide default
     *
     * Returns null if tables don't exist or no matching resource is found.
     */
    public function resolve(string $endpointPath, string $resourceName): ?EndpointParameterData
    {
        if (! $this->checkTablesExist()) {
            return null;
        }

        $resource = $this->findResource($resourceName, $endpointPath);

        if (! $resource) {
            return null;
        }

        $resourceId = $resource->id;

        return new EndpointParameterData(
            orderableFields: $this->getOrderableFields($resourceId),
            defaultOrder: $this->getDefaultOrder($resourceId),
            filterableFields: $this->getFilterableFields($resourceId),
            defaultFilters: $this->getDefaultFilters($resourceId),
        );
    }

    /**
     * Check if the required tables exist (cached for process lifetime).
     */
    private function checkTablesExist(): bool
    {
        if ($this->tablesExist !== null) {
            return $this->tablesExist;
        }

        $this->tablesExist = Schema::hasTable('api_resources');

        if (! $this->tablesExist && ! $this->warningLogged) {
            Log::warning(
                'OpenAPI endpoint parameter enrichment: api_resources table does not exist. '
                . 'Skipping parameter enrichment for all endpoints.'
            );
            $this->warningLogged = true;
        }

        return $this->tablesExist;
    }

    /**
     * Find the api_resource record using two-tier lookup.
     *
     * First tries (name, endpoint), then falls back to (name, null).
     */
    private function findResource(string $resourceName, string $endpointPath): ?object
    {
        // Tier 1: endpoint-specific match
        $resource = DB::table('api_resources')
            ->where('name', $resourceName)
            ->where('endpoint', $endpointPath)
            ->first();

        if ($resource) {
            return $resource;
        }

        // Tier 2: resource-wide default (endpoint is null)
        return DB::table('api_resources')
            ->where('name', $resourceName)
            ->whereNull('endpoint')
            ->first();
    }

    /**
     * Get orderable field names for the given resource.
     *
     * @return array<string>
     */
    private function getOrderableFields(int $resourceId): array
    {
        return DB::table('resource_orderable_fields')
            ->where('api_resource_id', $resourceId)
            ->pluck('field')
            ->all();
    }

    /**
     * Get default order entries for the given resource.
     *
     * Joins with resource_orderable_fields to get the field name,
     * ordered by sort_order.
     *
     * @return array<array{0: string, 1: string}>
     */
    private function getDefaultOrder(int $resourceId): array
    {
        return DB::table('resource_default_order_entries')
            ->join(
                'resource_orderable_fields',
                'resource_default_order_entries.resource_orderable_field_id',
                '=',
                'resource_orderable_fields.id'
            )
            ->where('resource_orderable_fields.api_resource_id', $resourceId)
            ->orderBy('resource_default_order_entries.sort_order')
            ->select([
                'resource_orderable_fields.field',
                'resource_default_order_entries.direction',
            ])
            ->get()
            ->map(fn (object $row) => [$row->field, $row->direction])
            ->all();
    }

    /**
     * Get filterable field names for the given resource.
     *
     * @return array<string>
     */
    private function getFilterableFields(int $resourceId): array
    {
        return DB::table('resource_filterable_fields')
            ->where('api_resource_id', $resourceId)
            ->pluck('field')
            ->all();
    }

    /**
     * Get default filters for the given resource.
     *
     * Joins with resource_filterable_fields to get the field name.
     *
     * @return array<array{0: string, 1: string}>
     */
    private function getDefaultFilters(int $resourceId): array
    {
        return DB::table('resource_default_filters')
            ->join(
                'resource_filterable_fields',
                'resource_default_filters.resource_filterable_field_id',
                '=',
                'resource_filterable_fields.id'
            )
            ->where('resource_filterable_fields.api_resource_id', $resourceId)
            ->select([
                'resource_filterable_fields.field',
                'resource_default_filters.value',
            ])
            ->get()
            ->map(fn (object $row) => [$row->field, $row->value])
            ->all();
    }
}
