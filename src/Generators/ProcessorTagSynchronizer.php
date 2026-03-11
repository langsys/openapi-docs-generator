<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

class ProcessorTagSynchronizer
{
    private array $addedTags = [];

    /**
     * Synchronize a tag order processor file with the tags found in the OpenAPI spec.
     *
     * Detects new tags not in the processor and inserts them in logical positions
     * (grouped by prefix, e.g. "Organization - Domains" goes with other "Organization" tags).
     *
     * Returns the list of tags that were added, or empty if none.
     */
    public function synchronize(string $processorFile, string $openApiFile): array
    {
        $this->addedTags = [];

        if (!file_exists($processorFile) || !file_exists($openApiFile)) {
            return [];
        }

        $specTags = $this->extractTagsFromSpec($openApiFile);
        if (empty($specTags)) {
            return [];
        }

        $source = file_get_contents($processorFile);
        $processorTags = $this->extractTagsFromProcessor($source);

        if (empty($processorTags)) {
            return [];
        }

        $newTags = array_diff($specTags, $processorTags);
        if (empty($newTags)) {
            return [];
        }

        $updatedTags = $this->insertTagsLogically($processorTags, $newTags);
        $updatedSource = $this->rebuildProcessorSource($source, $updatedTags);

        file_put_contents($processorFile, $updatedSource);

        $this->addedTags = array_values($newTags);
        return $this->addedTags;
    }

    /**
     * Extract unique tag names used by endpoints in the OpenAPI JSON.
     */
    private function extractTagsFromSpec(string $openApiFile): array
    {
        $data = json_decode(file_get_contents($openApiFile), true);
        if (!is_array($data)) {
            return [];
        }

        $tags = [];
        foreach ($data['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (!is_array($operation)) {
                    continue;
                }
                foreach ($operation['tags'] ?? [] as $tag) {
                    $tags[$tag] = true;
                }
            }
        }

        return array_keys($tags);
    }

    /**
     * Parse tag names from the processor source code.
     * Looks for patterns like: new Tag(['name' => 'TagName'])
     */
    private function extractTagsFromProcessor(string $source): array
    {
        $tags = [];
        if (preg_match_all("/new\s+Tag\s*\(\s*\[\s*'name'\s*=>\s*'([^']+)'\s*\]\s*\)/", $source, $matches)) {
            $tags = $matches[1];
        }
        return $tags;
    }

    /**
     * Insert new tags into the ordered list at logical positions.
     *
     * Strategy: if a new tag has a prefix (e.g. "Organization" from "Organization - Domains"),
     * insert it after the last existing tag with the same prefix. Otherwise append to the end
     * (before "Deprecated" if it exists).
     */
    private function insertTagsLogically(array $existingTags, array $newTags): array
    {
        $result = $existingTags;

        foreach ($newTags as $newTag) {
            $prefix = $this->extractPrefix($newTag);
            $insertPos = null;

            if ($prefix !== null) {
                // Find the last tag with the same prefix
                for ($i = count($result) - 1; $i >= 0; $i--) {
                    $existingPrefix = $this->extractPrefix($result[$i]);
                    if ($existingPrefix === $prefix || $result[$i] === $prefix) {
                        $insertPos = $i + 1;
                        break;
                    }
                }
            }

            if ($insertPos === null) {
                // No matching prefix — insert before "Deprecated" if it exists, otherwise append
                $deprecatedPos = array_search('Deprecated', $result);
                $insertPos = $deprecatedPos !== false ? $deprecatedPos : count($result);
            }

            array_splice($result, $insertPos, 0, [$newTag]);
        }

        return $result;
    }

    /**
     * Extract the prefix from a tag name.
     * "Organization - Domains" => "Organization"
     * "Organization" => null (it IS the prefix, not a sub-tag)
     */
    private function extractPrefix(string $tag): ?string
    {
        if (str_contains($tag, ' - ')) {
            return trim(explode(' - ', $tag, 2)[0]);
        }
        return null;
    }

    /**
     * Rebuild the processor source file with the updated tag list.
     */
    private function rebuildProcessorSource(string $source, array $tags): string
    {
        // Match the entire tags array assignment: $openapi->tags = [...];
        $pattern = '/(\$openapi->tags\s*=\s*\[)(.*?)(\];)/s';

        if (!preg_match($pattern, $source, $match)) {
            return $source;
        }

        $indent = $this->detectIndent($source);
        $tagLines = [];
        foreach ($tags as $tag) {
            $escaped = str_replace("'", "\\'", $tag);
            $tagLines[] = "{$indent}{$indent}{$indent}new Tag(['name' => '{$escaped}'])";
        }

        $newBlock = $match[1] . "\n" . implode(",\n", $tagLines) . "\n{$indent}{$indent}];";

        return preg_replace($pattern, $newBlock, $source, 1);
    }

    /**
     * Detect the indentation style used in the file.
     */
    private function detectIndent(string $source): string
    {
        if (preg_match('/^( +)(?:new Tag|public|private|protected|\$)/m', $source, $match)) {
            $totalSpaces = strlen($match[1]);
            // Assume the match is at 2+ indent levels, try to find single level
            if ($totalSpaces >= 8) {
                return str_repeat(' ', 4);
            }
            return $match[1];
        }
        return '    ';
    }
}
