<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

class SecurityDefinitions
{
    public function __construct(
        private array $securitySchemesConfig,
        private array $securityConfig,
    ) {}

    /**
     * Inject security schemes and requirements into the generated OpenAPI JSON file.
     *
     * Config-defined security is additive to annotation-defined security.
     * Existing schemes/requirements from annotations are preserved.
     */
    public function generate(string $docsFile): void
    {
        $json = json_decode(file_get_contents($docsFile), true);

        $this->injectSecuritySchemes($json);
        $this->injectSecurity($json);

        file_put_contents($docsFile, json_encode(
            $json,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    /**
     * Merge config-defined security schemes into components.securitySchemes.
     *
     * Existing schemes from annotations are preserved. Config schemes with
     * the same name will NOT overwrite annotation-defined ones.
     */
    private function injectSecuritySchemes(array &$json): void
    {
        if (empty($this->securitySchemesConfig)) {
            return;
        }

        if (! isset($json['components'])) {
            $json['components'] = [];
        }

        if (! isset($json['components']['securitySchemes'])) {
            $json['components']['securitySchemes'] = [];
        }

        foreach ($this->securitySchemesConfig as $schemeName => $schemeDefinition) {
            if (! isset($json['components']['securitySchemes'][$schemeName])) {
                $json['components']['securitySchemes'][$schemeName] = $schemeDefinition;
            }
        }
    }

    /**
     * Merge config-defined security requirements into the top-level security array.
     *
     * Each requirement is an object like {"sanctum": []}. Config requirements
     * are appended only if an equivalent entry doesn't already exist.
     */
    private function injectSecurity(array &$json): void
    {
        if (empty($this->securityConfig)) {
            return;
        }

        if (! isset($json['security'])) {
            $json['security'] = [];
        }

        foreach ($this->securityConfig as $requirement) {
            if (! $this->securityRequirementExists($json['security'], $requirement)) {
                $json['security'][] = $requirement;
            }
        }
    }

    /**
     * Check if a security requirement already exists in the security array.
     *
     * A requirement matches if it has the same key(s) — e.g. {"sanctum": []}.
     */
    private function securityRequirementExists(array $existing, array $requirement): bool
    {
        $requirementKeys = array_keys($requirement);

        foreach ($existing as $entry) {
            if (array_keys($entry) === $requirementKeys) {
                return true;
            }
        }

        return false;
    }
}
