<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AiGateway;

/** A finite scalar-object format request; it declares shape, never expected values or truth. */
final readonly class StructuredOutput
{
    /** @var array<string,list<string>> */
    private array $fields;

    /**
     * All named fields are required; nested schemas, constants and extra fields are unsupported.
     *
     * @param array<string,mixed> $fields Raw scalar-type declarations, validated here (at most 64 fields).
     */
    public function __construct(private string $name, array $fields)
    {
        $identifier = static fn ($v): bool => is_string($v) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $v) === 1;
        if (!$identifier($name) || $fields === [] || count($fields) > 64) {
            throw new \InvalidArgumentException('Structured output requires a name and 1 to 64 scalar fields.');
        }
        foreach ($fields as $field => $types) {
            if (!$identifier($field) || !is_array($types) || !array_is_list($types) || $types === []
                || array_filter($types, static fn ($t): bool => !is_string($t) || !in_array($t, ['string', 'number', 'integer', 'boolean', 'null'], true)) !== []
                || count(array_unique($types)) !== count($types)) {
                throw new \InvalidArgumentException('Structured output fields require distinct scalar JSON types.');
            }
            sort($types);
            $fields[$field] = $types;
        }
        ksort($fields);
        $this->fields = $fields;
    }

    /** The explicit OpenAI-compatible wire format, with no prompt or answer values.
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $properties = [];
        foreach ($this->fields as $field => $types) {
            $properties[$field] = ['type' => count($types) === 1 ? $types[0] : $types];
        }
        return ['type' => 'json_schema', 'json_schema' => ['name' => $this->name, 'strict' => true,
            'schema' => ['type' => 'object', 'properties' => $properties,
                'required' => array_keys($properties), 'additionalProperties' => false]]];
    }
}
