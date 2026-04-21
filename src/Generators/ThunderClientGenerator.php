<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use Illuminate\Support\Str;

class ThunderClientGenerator
{
    private array $warnings = [];

    public function __construct(
        private string $openApiFile,
        private string $collectionFile,
        private ?string $collectionName,
        private array $authSchemes,
        private string $defaultAuth,
        private string $baseUrlVariable,
        private array $skipPathSegments,
        private array $defaultHeaders,
        private ?array $environmentConfig,
        private ?string $environmentFile,
        private bool $refresh = false,
        private bool $wipe = false,
    ) {}

    public function generate(): void
    {
        $openApi = $this->loadOpenApi();
        if ($openApi === null) {
            return;
        }

        if ($this->wipe && file_exists($this->collectionFile)) {
            unlink($this->collectionFile);
        }

        $existing = $this->loadExistingCollection();
        $collection = $this->resolveCollectionShell($existing, $openApi);

        $existingByName = $this->buildExistingByName($existing);
        $folders = $existing['folders'] ?? [];
        $requests = $this->refresh ? [] : ($existing['requests'] ?? []);
        $folderMap = $this->buildFolderMap($folders);

        $globalSecurity = $openApi['security'] ?? [];
        $schemas = $openApi['components']['schemas'] ?? [];
        $sortNum = $this->nextSortNum($requests);

        foreach ($openApi['paths'] ?? [] as $path => $pathItem) {
            foreach ($this->httpMethods() as $method) {
                if (!isset($pathItem[$method])) {
                    continue;
                }

                $operation = $pathItem[$method];

                $folderName = $this->resolveFolderName($operation, $path);
                $folderId = '';
                if ($folderName !== null) {
                    if (!isset($folderMap[$folderName])) {
                        $folder = $this->buildFolder($folderName, $collection['_id']);
                        $folders[] = $folder;
                        $folderMap[$folderName] = $folder['_id'];
                    }
                    $folderId = $folderMap[$folderName];
                }

                $url = '{{' . $this->baseUrlVariable . '}}' . $this->convertPathParams($path);
                $name = $operation['summary'] ?? strtoupper($method) . ' ' . $path;
                $headers = $this->buildHeaders($method);
                $body = $this->buildBody($method, $operation, $schemas);

                $schemeNames = $this->resolveSchemeNames($operation, $globalSecurity);
                $authVariants = $this->resolveAuthVariants($schemeNames);

                if (empty($authVariants)) {
                    $authVariants = [['name_suffix' => null, 'auth' => ['type' => 'none'], 'extra_headers' => []]];
                }

                $multiScheme = count($authVariants) > 1;

                foreach ($authVariants as $variant) {
                    $requestName = $name;
                    if ($multiScheme && $variant['name_suffix'] !== null) {
                        $requestName .= ' (' . $variant['name_suffix'] . ')';
                    }

                    $existingRequest = $existingByName[$requestName] ?? null;

                    // Default behavior: skip if already exists so manual edits are preserved
                    if ($existingRequest !== null && !$this->refresh) {
                        continue;
                    }

                    $requestHeaders = array_merge($headers, $variant['extra_headers']);

                    $requests[] = $this->mergeRequest(
                        existing: $existingRequest,
                        colId: $collection['_id'],
                        containerId: $folderId,
                        name: $requestName,
                        url: $url,
                        method: strtoupper($method),
                        headers: $requestHeaders,
                        body: $body,
                        auth: $variant['auth'],
                        sortNum: $sortNum,
                    );
                    $sortNum += 10000;
                }
            }
        }

        $tagOrder = $this->extractTagOrder($openApi);
        $collection['folders'] = $this->sortFolders($folders, $tagOrder);
        $collection['requests'] = $this->sortRequests($requests, $collection['folders']);

        $this->writeJson($this->collectionFile, $collection);
        $this->generateEnvironment($collection['requests']);
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    private function loadOpenApi(): ?array
    {
        if (!file_exists($this->openApiFile)) {
            $this->warnings[] = "OpenAPI file not found: {$this->openApiFile}";
            return null;
        }

        $data = json_decode(file_get_contents($this->openApiFile), true);
        if (!is_array($data)) {
            $this->warnings[] = "Invalid JSON in OpenAPI file: {$this->openApiFile}";
            return null;
        }

        return $data;
    }

    private function loadExistingCollection(): ?array
    {
        if (!file_exists($this->collectionFile)) {
            return null;
        }

        $data = json_decode(file_get_contents($this->collectionFile), true);
        return is_array($data) ? $data : null;
    }

    private function resolveCollectionShell(?array $existing, array $openApi): array
    {
        if ($existing !== null) {
            return [
                '_id' => $existing['_id'],
                'colName' => $existing['colName'],
                'created' => $existing['created'],
                'sortNum' => $existing['sortNum'] ?? 10000,
            ];
        }

        $name = $this->collectionName ?? $openApi['info']['title'] ?? 'API';
        $now = $this->timestamp();

        return [
            '_id' => $this->uuid(),
            'colName' => $name,
            'created' => $now,
            'sortNum' => 10000,
        ];
    }

    private function buildExistingByName(?array $existing): array
    {
        $lookup = [];
        if ($existing === null) {
            return $lookup;
        }

        foreach ($existing['requests'] ?? [] as $request) {
            if (isset($request['name'])) {
                $lookup[$request['name']] = $request;
            }
        }

        return $lookup;
    }

    private function mergeRequest(
        ?array $existing,
        string $colId,
        string $containerId,
        string $name,
        string $url,
        string $method,
        array $headers,
        array $body,
        array $auth,
        int $sortNum,
    ): array {
        if ($existing === null) {
            return $this->buildRequest(
                colId: $colId,
                containerId: $containerId,
                name: $name,
                url: $url,
                method: $method,
                headers: $headers,
                body: $body,
                auth: $auth,
                sortNum: $sortNum,
            );
        }

        // Refresh url, headers, auth from spec. Preserve _id, created, tests.
        // Only regenerate body if existing body is empty (don't clobber user-edited payloads).
        $existingBodyRaw = $existing['body']['raw'] ?? '';
        $mergedBody = empty($existingBodyRaw) ? $body : $existing['body'];

        return [
            '_id' => $existing['_id'] ?? $this->uuid(),
            'colId' => $colId,
            'containerId' => $containerId,
            'name' => $name,
            'url' => $url,
            'method' => $method,
            'sortNum' => $sortNum,
            'created' => $existing['created'] ?? $this->timestamp(),
            'modified' => $this->timestamp(),
            'headers' => $headers,
            'body' => $mergedBody,
            'auth' => $auth,
            'tests' => $existing['tests'] ?? [],
        ];
    }

    private function convertPathParams(string $path): string
    {
        return preg_replace('/\{([^}]+)\}/', '{{$1}}', $path);
    }

    private function buildFolderMap(array $folders): array
    {
        $map = [];
        foreach ($folders as $folder) {
            $map[$folder['name']] = $folder['_id'];
        }
        return $map;
    }

    private function extractTagOrder(array $openApi): array
    {
        $tags = $openApi['tags'] ?? [];
        $order = [];
        foreach ($tags as $index => $tag) {
            if (isset($tag['name'])) {
                $order[$tag['name']] = $index;
            }
        }
        return $order;
    }

    private function sortFolders(array $folders, array $tagOrder): array
    {
        usort($folders, function (array $a, array $b) use ($tagOrder) {
            $posA = $tagOrder[$a['name']] ?? PHP_INT_MAX;
            $posB = $tagOrder[$b['name']] ?? PHP_INT_MAX;
            return $posA <=> $posB;
        });

        // Reassign sortNum to reflect new order
        foreach ($folders as $i => &$folder) {
            $folder['sortNum'] = ($i + 1) * 10000;
        }

        return $folders;
    }

    private function sortRequests(array $requests, array $sortedFolders): array
    {
        // Build folder order map: folderId => position
        $folderOrder = [];
        foreach ($sortedFolders as $index => $folder) {
            $folderOrder[$folder['_id']] = $index;
        }

        usort($requests, function (array $a, array $b) use ($folderOrder) {
            $folderPosA = $folderOrder[$a['containerId']] ?? PHP_INT_MAX;
            $folderPosB = $folderOrder[$b['containerId']] ?? PHP_INT_MAX;

            if ($folderPosA !== $folderPosB) {
                return $folderPosA <=> $folderPosB;
            }

            // Within the same folder, preserve original order by sortNum
            return ($a['sortNum'] ?? 0) <=> ($b['sortNum'] ?? 0);
        });

        // Reassign sortNum sequentially
        foreach ($requests as $i => &$request) {
            $request['sortNum'] = ($i + 1) * 10000;
        }

        return $requests;
    }

    private function resolveFolderName(array $operation, string $path): ?string
    {
        if (!empty($operation['tags']) && is_array($operation['tags'])) {
            return $operation['tags'][0];
        }

        $segments = array_values(array_filter(explode('/', $path)));
        foreach ($segments as $segment) {
            if (str_starts_with($segment, '{')) {
                continue;
            }
            if (in_array(strtolower($segment), array_map('strtolower', $this->skipPathSegments), true)) {
                continue;
            }
            return ucfirst($segment);
        }

        return null;
    }

    private function buildFolder(string $name, string $collectionId): array
    {
        return [
            '_id' => $this->uuid(),
            'name' => $name,
            'containerId' => '',
            'created' => $this->timestamp(),
            'sortNum' => 10000,
        ];
    }

    private function buildHeaders(string $method): array
    {
        $headers = $this->defaultHeaders;

        if (in_array(strtolower($method), ['post', 'put', 'patch'])) {
            $headers[] = ['name' => 'Content-Type', 'value' => 'application/json'];
        }

        return $headers;
    }

    private function buildBody(string $method, array $operation, array $schemas): array
    {
        if (!in_array(strtolower($method), ['post', 'put', 'patch'])) {
            return ['type' => 'json', 'raw' => '', 'form' => []];
        }

        $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;
        if ($schema === null) {
            return ['type' => 'json', 'raw' => '', 'form' => []];
        }

        $body = $this->resolveSchemaToExample($schema, $schemas, 0, []);
        $raw = is_array($body) ? json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

        return ['type' => 'json', 'raw' => $raw, 'form' => []];
    }

    private function resolveSchemaToExample(array $schema, array $allSchemas, int $depth, array $visited): mixed
    {
        // Deep nesting guard — rely on $visited for cycle detection primarily
        if ($depth > 10) {
            return new \stdClass();
        }

        if (isset($schema['$ref'])) {
            $refName = $this->extractRefName($schema['$ref']);
            if ($refName === null || isset($visited[$refName]) || !isset($allSchemas[$refName])) {
                return new \stdClass();
            }
            $visited[$refName] = true;
            return $this->resolveSchemaToExample($allSchemas[$refName], $allSchemas, $depth + 1, $visited);
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $merged = [];
            foreach ($schema['allOf'] as $subSchema) {
                $result = $this->resolveSchemaToExample($subSchema, $allSchemas, $depth + 1, $visited);
                if (is_array($result)) {
                    $merged = array_merge($merged, $result);
                } elseif ($result instanceof \stdClass) {
                    // Preserve object shape even when nested resolution bailed (e.g. cycle)
                    return $result;
                }
            }
            return $merged;
        }

        $type = $schema['type'] ?? 'object';

        if ($type === 'array') {
            $items = $schema['items'] ?? [];
            return [$this->resolveSchemaToExample($items, $allSchemas, $depth + 1, $visited)];
        }

        if ($type !== 'object' && !isset($schema['properties'])) {
            return $this->primitiveExample($schema);
        }

        $result = [];
        foreach ($schema['properties'] ?? [] as $propName => $propSchema) {
            if (isset($propSchema['example'])) {
                $result[$propName] = $propSchema['example'];
            } elseif (isset($propSchema['enum']) && !empty($propSchema['enum'])) {
                $result[$propName] = $propSchema['enum'][0];
            } elseif (($propSchema['type'] ?? null) === 'array') {
                $items = $propSchema['items'] ?? [];
                $result[$propName] = [$this->resolveSchemaToExample($items, $allSchemas, $depth + 1, $visited)];
            } elseif (isset($propSchema['$ref']) || isset($propSchema['properties']) || isset($propSchema['allOf'])) {
                $result[$propName] = $this->resolveSchemaToExample($propSchema, $allSchemas, $depth + 1, $visited);
            } else {
                $result[$propName] = $this->primitiveExample($propSchema);
            }
        }

        return $result;
    }

    private function primitiveExample(array $schema): mixed
    {
        if (isset($schema['example'])) {
            return $schema['example'];
        }

        if (isset($schema['enum']) && !empty($schema['enum'])) {
            return $schema['enum'][0];
        }

        return match ($schema['type'] ?? 'string') {
            'integer', 'number' => 0,
            'boolean' => false,
            'array' => [],
            'object' => new \stdClass(),
            default => '',
        };
    }

    private function extractRefName(string $ref): ?string
    {
        if (preg_match('#/components/schemas/(.+)$#', $ref, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function resolveSchemeNames(array $operation, array $globalSecurity): array
    {
        $security = $operation['security'] ?? $globalSecurity;
        if (empty($security)) {
            if ($this->defaultAuth !== 'none') {
                return [$this->defaultAuth];
            }
            return [];
        }

        $names = [];
        foreach ($security as $entry) {
            if (is_array($entry)) {
                foreach (array_keys($entry) as $schemeName) {
                    $names[] = $schemeName;
                }
            }
        }

        return array_unique($names);
    }

    private function resolveAuthVariants(array $schemeNames): array
    {
        $variants = [];

        foreach ($schemeNames as $schemeName) {
            if (!isset($this->authSchemes[$schemeName])) {
                $this->warnings[] = "Unknown auth scheme '{$schemeName}' — skipping.";
                continue;
            }

            $scheme = $this->authSchemes[$schemeName];
            $type = $scheme['type'] ?? 'none';

            $variant = [
                'name_suffix' => $schemeName,
                'auth' => ['type' => 'none'],
                'extra_headers' => [],
            ];

            if ($type === 'bearer') {
                $variant['auth'] = ['type' => 'bearer'];
            } elseif ($type === 'header') {
                $variant['auth'] = ['type' => 'none'];
                $variant['extra_headers'] = [
                    ['name' => $scheme['header_name'] ?? 'Authorization', 'value' => $scheme['value'] ?? ''],
                ];
            } elseif ($type === 'basic') {
                $variant['auth'] = ['type' => 'basic'];
            }

            $variants[] = $variant;
        }

        return $variants;
    }

    private function buildRequest(
        string $colId,
        string $containerId,
        string $name,
        string $url,
        string $method,
        array $headers,
        array $body,
        array $auth,
        int $sortNum,
    ): array {
        $now = $this->timestamp();

        return [
            '_id' => $this->uuid(),
            'colId' => $colId,
            'containerId' => $containerId,
            'name' => $name,
            'url' => $url,
            'method' => $method,
            'sortNum' => $sortNum,
            'created' => $now,
            'modified' => $now,
            'headers' => $headers,
            'body' => $body,
            'auth' => $auth,
            'tests' => [],
        ];
    }

    private function generateEnvironment(array $requests = []): void
    {
        if ($this->environmentConfig === null || $this->environmentFile === null) {
            return;
        }

        if (file_exists($this->environmentFile)) {
            return;
        }

        $variables = $this->environmentConfig['variables'] ?? [];
        $urlSuffix = $this->environmentConfig['url_suffix'] ?? '';
        $data = [];

        foreach ($variables as $varName => $varValue) {
            $resolved = $this->resolveEnvValue($varValue);

            if ($varName === $this->baseUrlVariable && $urlSuffix && str_starts_with($varValue, 'env:')) {
                $resolved = rtrim($resolved, '/') . $urlSuffix;
            }

            $data[] = ['name' => $varName, 'value' => $resolved];
        }

        // Auto-add placeholder entries for any {{variable}} referenced in auth schemes
        // or URL path params so users have a single place to set them.
        $autoVariables = array_merge(
            $this->extractAuthVariables(),
            $this->extractPathVariables($requests),
        );
        $existingNames = array_column($data, 'name');
        foreach (array_unique($autoVariables) as $varName) {
            if (!in_array($varName, $existingNames, true) && $varName !== $this->baseUrlVariable) {
                $data[] = ['name' => $varName, 'value' => ''];
            }
        }

        $now = $this->timestamp();
        $env = [
            '_id' => $this->uuid(),
            'name' => $this->environmentConfig['name'] ?? 'Local',
            'default' => true,
            'sortNum' => 10000,
            'created' => $now,
            'modified' => $now,
            'data' => $data,
        ];

        $this->writeJson($this->environmentFile, $env);
    }

    private function nextSortNum(array $requests): int
    {
        if (empty($requests)) {
            return 10000;
        }

        $max = 0;
        foreach ($requests as $request) {
            $max = max($max, $request['sortNum'] ?? 0);
        }

        return $max + 10000;
    }

    private function extractAuthVariables(): array
    {
        $variables = [];
        foreach ($this->authSchemes as $scheme) {
            foreach ($scheme as $value) {
                if (!is_string($value)) {
                    continue;
                }
                if (preg_match_all('/\{\{(\w+)\}\}/', $value, $matches)) {
                    foreach ($matches[1] as $varName) {
                        $variables[$varName] = true;
                    }
                }
            }
        }
        return array_keys($variables);
    }

    private function extractPathVariables(array $requests): array
    {
        $variables = [];
        foreach ($requests as $request) {
            $url = $request['url'] ?? '';
            if (preg_match_all('/\{\{(\w+)\}\}/', $url, $matches)) {
                foreach ($matches[1] as $varName) {
                    $variables[$varName] = true;
                }
            }
        }
        return array_keys($variables);
    }

    private function resolveEnvValue(string $value): string
    {
        if (str_starts_with($value, 'env:')) {
            $envKey = substr($value, 4);
            return (string) env($envKey, '');
        }

        return $value;
    }

    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    private function httpMethods(): array
    {
        return ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    private function timestamp(): string
    {
        return now()->toIso8601ZuluString();
    }
}
