<?php

namespace Langsys\OpenApiDocsGenerator\Generators;

use Langsys\OpenApiDocsGenerator\Data\ErrorDefinition;
use Langsys\OpenApiDocsGenerator\Exceptions\OpenApiDocsException;
use OpenApi\Annotations as OA;

/**
 * The shape of an API error response, defined in exactly one place:
 *
 *   { status: false, data: [], error: { message, code, template, params, details, ...#[EnvelopeField] properties } }
 *
 * Response-level names come from `errors.response_fields`, error-object names from
 * `errors.error_fields`; a null name omits that field. The `error` object and its
 * `code` can't be omitted: everything lives in the one, and shared-status
 * responses discriminate on the other.
 *
 * DtoSchemaBuilder builds each error's schemas with an instance of this class and
 * OperationErrorAttacher builds shared-status responses with the same instance, so
 * an attached response can never disagree with the schemas it references.
 *
 * The schemas are deliberately open (no `additionalProperties: false`), so an app
 * can add fields it chooses not to document, such as debug output shown only to
 * allow-listed developers, without failing response validation.
 */
final class ErrorEnvelope
{
    public const DEFAULT_RESPONSE_FIELDS = [
        'status' => 'status',
        'data' => 'data',
        'error' => 'error',
    ];

    public const DEFAULT_ERROR_FIELDS = [
        'message' => 'message',
        'code' => 'code',
        'template' => 'template',
        'params' => 'params',
        'details' => 'details',
    ];

    /** @var array{status: ?string, data: ?string, error: string} */
    private array $responseFields;

    /** @var array{message: ?string, code: string, details: ?string} */
    private array $errorFields;

    /**
     * @param  array<string, mixed>  $errorConfig  A documentation set's `errors` block.
     *
     * @throws OpenApiDocsException on the removed `fields` key, an unknown field key, a
     *                              non-string or duplicate name, or an omitted `error`/`code`.
     */
    public function __construct(array $errorConfig = [])
    {
        if (array_key_exists('fields', $errorConfig)) {
            throw new OpenApiDocsException(
                'errors.fields was replaced by errors.response_fields (status, data, error) and '
                . 'errors.error_fields (message, code, details): error detail now lives inside the `error` object.'
            );
        }

        $this->responseFields = $this->resolveFields(
            'response_fields',
            self::DEFAULT_RESPONSE_FIELDS,
            $errorConfig,
            required: 'error',
            because: 'the error object is emitted under it',
        );
        $this->errorFields = $this->resolveFields(
            'error_fields',
            self::DEFAULT_ERROR_FIELDS,
            $errorConfig,
            required: 'code',
            because: 'shared-status responses discriminate on it',
        );
    }

    /** Name of the response-level property holding the error object. */
    public function errorField(): string
    {
        return $this->responseFields['error'];
    }

    /** Name of the error-object property holding the code. */
    public function codeField(): string
    {
        return $this->errorFields['code'];
    }

    /** Where the code sits in a response, for descriptions, e.g. "error.code". */
    public function codePath(): string
    {
        return $this->errorField() . '.' . $this->codeField();
    }

    /**
     * `{Name}Body`, the error object: `message` (MESSAGE with its markers filled from
     * #[Example] values, as the example), `code` (an enum of the one code), `template`
     * (MESSAGE verbatim), `params` (one property per MESSAGE marker, omitted when there
     * are none), `details` (a `$ref` to the details schema, when the class has one),
     * then the class's #[EnvelopeField] properties.
     *
     * @param  array<int, OA\Property>  $envelopeProperties  Built from #[EnvelopeField] properties.
     * @param  array<int, string>  $envelopeRequired  Names of the non-nullable ones.
     * @param  array<int, OA\Property>  $paramProperties  Built from the properties MESSAGE's markers name.
     * @param  string|null  $messageExample  MESSAGE with markers filled in; defaults to MESSAGE.
     *
     * @throws OpenApiDocsException when an envelope property reuses an error-object field name.
     */
    public function bodySchema(
        ErrorDefinition $definition,
        array $envelopeProperties = [],
        array $envelopeRequired = [],
        array $paramProperties = [],
        ?string $messageExample = null,
    ): OA\Schema {
        $reserved = array_values(array_filter($this->errorFields, static fn (?string $name): bool => $name !== null));

        foreach ($envelopeProperties as $property) {
            if (in_array($property->property, $reserved, true)) {
                throw new OpenApiDocsException(sprintf(
                    '%s marks `%s` as #[EnvelopeField], but the error object already has a `%s` field; '
                    . 'rename the property, or rename the field in errors.error_fields.',
                    $definition->className,
                    $property->property,
                    $property->property,
                ));
            }
        }

        $properties = [];
        $required = [];
        $templateName = $this->errorFields['template'];
        $paramsName = $paramProperties === [] ? null : $this->errorFields['params'];

        if ($name = $this->errorFields['message']) {
            $properties[] = new OA\Property([
                'property' => $name,
                'type' => 'string',
                'description' => $templateName !== null && $paramsName !== null
                    ? 'Human-readable message: `' . $templateName . '` with `' . $paramsName . '` filled in'
                    : 'Human-readable error message',
                'example' => $messageExample ?? $definition->message,
            ]);
            $required[] = $name;
        }

        $properties[] = new OA\Property([
            'property' => $this->codeField(),
            'type' => 'string',
            'description' => 'Machine-readable error code',
            'enum' => [$definition->code],
            'example' => $definition->code,
        ]);
        $required[] = $this->codeField();

        if ($templateName !== null) {
            $properties[] = new OA\Property([
                'property' => $templateName,
                'type' => 'string',
                'description' => $this->errorFields['params'] !== null
                    ? 'Source text to translate; `{name}` markers are filled from `' . $this->errorFields['params'] . '`.'
                    : 'Source text to translate.',
                'example' => $definition->message,
            ]);
            $required[] = $templateName;
        }

        if ($paramsName !== null) {
            $properties[] = new OA\Property([
                'property' => $paramsName,
                'type' => 'object',
                'description' => 'Values for the `{name}` markers in the message template',
                'required' => array_map(static fn (OA\Property $param): string => $param->property, $paramProperties),
                'properties' => array_values($paramProperties),
            ]);
            $required[] = $paramsName;
        }

        if ($definition->hasDetails && ($name = $this->errorFields['details'])) {
            $properties[] = new OA\Property([
                'property' => $name,
                'description' => 'Error details',
                'type' => 'object',
                'allOf' => [new OA\Schema(['ref' => self::schemaRef($definition->schemaName)])],
            ]);
        }

        return new OA\Schema([
            'schema' => $definition->bodySchemaName,
            'type' => 'object',
            'required' => array_merge($required, $envelopeRequired),
            'properties' => array_merge($properties, $envelopeProperties),
        ]);
    }

    /**
     * `{Name}Response`, the envelope around one error's body.
     */
    public function responseSchema(ErrorDefinition $definition): OA\Schema
    {
        return new OA\Schema(['schema' => $definition->responseSchemaName] + $this->envelope(new OA\Property([
            'property' => $this->errorField(),
            'description' => 'Error detail',
            'type' => 'object',
            'allOf' => [new OA\Schema(['ref' => self::schemaRef($definition->bodySchemaName)])],
        ])));
    }

    /**
     * The response schema for a status several errors share.
     *
     * OpenAPI 3.0 can only discriminate on a property at the top level of each oneOf
     * variant, and `code` sits inside `error`. So the envelope stays single and the
     * `oneOf` goes on its `error` property, over the `{Name}Body` schemas, with the
     * discriminator on `code` there.
     *
     * @param  array<int, ErrorDefinition>  $definitions
     */
    public function sharedStatusSchema(array $definitions): OA\Schema
    {
        $variants = [];
        $mapping = [];

        foreach ($definitions as $definition) {
            $ref = self::schemaRef($definition->bodySchemaName);
            $variants[] = new OA\Schema(['ref' => $ref]);
            $mapping[$definition->code] = $ref;
        }

        return new OA\Schema($this->envelope(new OA\Property([
            'property' => $this->errorField(),
            'description' => 'One of the errors listed above; `' . $this->codeField() . '` says which',
            'oneOf' => $variants,
            'discriminator' => new OA\Discriminator([
                'propertyName' => $this->codeField(),
                'mapping' => $mapping,
            ]),
        ])));
    }

    /**
     * A whole response body for one error, as an example value: the envelope's own
     * fields around the given error object, under the configured names.
     *
     * @param  array<string, mixed>  $errorObject
     * @return array<string, mixed>
     */
    public function exampleEnvelope(array $errorObject): array
    {
        $body = [];

        if ($name = $this->responseFields['status']) {
            $body[$name] = false;
        }

        if ($name = $this->responseFields['data']) {
            $body[$name] = [];
        }

        $body[$this->errorField()] = $errorObject;

        return $body;
    }

    /**
     * The error object for an example when its `{Name}Body` schema isn't available:
     * what the definition itself knows, under the configured names.
     *
     * @return array<string, mixed>
     */
    public function exampleErrorObject(ErrorDefinition $definition): array
    {
        $object = [];

        if ($name = $this->errorFields['message']) {
            $object[$name] = $definition->message;
        }

        $object[$this->codeField()] = $definition->code;

        if ($name = $this->errorFields['template']) {
            $object[$name] = $definition->message;
        }

        return $object;
    }

    /**
     * Response-level schema keys around the given `error` property.
     *
     * @return array{type: string, required: array<int, string>, properties: array<int, OA\Property>}
     */
    private function envelope(OA\Property $error): array
    {
        $properties = [];
        $required = [];

        if ($name = $this->responseFields['status']) {
            $properties[] = new OA\Property([
                'property' => $name,
                'type' => 'boolean',
                'description' => 'Always false for errors',
                'example' => false,
            ]);
            $required[] = $name;
        }

        if ($name = $this->responseFields['data']) {
            $properties[] = new OA\Property([
                'property' => $name,
                'type' => 'array',
                'description' => 'Always empty on error',
                'maxItems' => 0,
                'items' => new OA\Items(['type' => 'object']),
                'example' => [],
            ]);
        }

        $properties[] = $error;
        $required[] = $this->errorField();

        return ['type' => 'object', 'required' => $required, 'properties' => $properties];
    }

    private static function schemaRef(string $schemaName): string
    {
        return '#/components/schemas/' . $schemaName;
    }

    /**
     * @param  array<string, ?string>  $defaults
     * @param  array<string, mixed>  $errorConfig
     * @return array<string, ?string>
     *
     * @throws OpenApiDocsException
     */
    private function resolveFields(string $key, array $defaults, array $errorConfig, string $required, string $because): array
    {
        $overrides = $errorConfig[$key] ?? [];

        if (! is_array($overrides)) {
            throw new OpenApiDocsException("errors.{$key} must be an array of field names.");
        }

        $unknown = array_diff(array_keys($overrides), array_keys($defaults));
        if ($unknown !== []) {
            throw new OpenApiDocsException(sprintf(
                'Unknown errors.%s key(s): %s. Allowed: %s.',
                $key,
                implode(', ', $unknown),
                implode(', ', array_keys($defaults)),
            ));
        }

        $fields = array_replace($defaults, $overrides);

        foreach ($fields as $field => $name) {
            if ($name !== null && (! is_string($name) || $name === '')) {
                throw new OpenApiDocsException("errors.{$key}.{$field} must be a non-empty string, or null to omit the field.");
            }
        }

        foreach (array_count_values(array_filter($fields, static fn (?string $name): bool => $name !== null)) as $name => $count) {
            if ($count > 1) {
                throw new OpenApiDocsException("errors.{$key} uses the name `{$name}` more than once.");
            }
        }

        if ($fields[$required] === null) {
            throw new OpenApiDocsException("errors.{$key}.{$required} cannot be null: {$because}.");
        }

        return $fields;
    }
}
