<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Schema validator for job args.
 *
 * Validates job arguments against JSON Schema definitions. Can be used
 * as middleware to enforce schema validation before job processing.
 */
class SchemaValidator
{
    /** @var array<string, array> URI => JSON Schema */
    private array $schemas = [];

    /**
     * Register a JSON Schema for a given URI.
     */
    public function register(string $uri, array $schema): self
    {
        $this->schemas[$uri] = $schema;
        return $this;
    }

    /**
     * Validate data against a registered schema.
     *
     * @return string[] Array of validation error messages (empty = valid)
     */
    public function validate(string $schemaUri, mixed $data): array
    {
        $schema = $this->schemas[$schemaUri] ?? null;
        if ($schema === null) {
            return ["Schema not found: {$schemaUri}"];
        }

        return $this->validateValue($data, $schema, '$');
    }

    /**
     * Check if data is valid against a schema.
     */
    public function isValid(string $schemaUri, mixed $data): bool
    {
        return $this->validate($schemaUri, $data) === [];
    }

    private function validateValue(mixed $value, array $schema, string $path): array
    {
        $errors = [];

        if (isset($schema['type'])) {
            $typeValid = match ($schema['type']) {
                'string' => is_string($value),
                'number' => is_int($value) || is_float($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && !array_is_list($value),
                'null' => $value === null,
                default => true,
            };
            if (!$typeValid) {
                $errors[] = "{$path}: expected {$schema['type']}, got " . gettype($value);
                return $errors;
            }
        }

        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $allowed = implode(', ', array_map('json_encode', $schema['enum']));
            $errors[] = "{$path}: value not in enum [{$allowed}]";
        }

        if (is_string($value)) {
            if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
                $errors[] = "{$path}: string shorter than minLength {$schema['minLength']}";
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
                $errors[] = "{$path}: string longer than maxLength {$schema['maxLength']}";
            }
            if (isset($schema['pattern']) && !preg_match("/{$schema['pattern']}/", $value)) {
                $errors[] = "{$path}: string does not match pattern {$schema['pattern']}";
            }
        }

        if (is_numeric($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path}: value below minimum {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path}: value above maximum {$schema['maximum']}";
            }
        }

        if (is_array($value) && ($schema['type'] ?? '') === 'object') {
            foreach ($schema['required'] ?? [] as $req) {
                if (!array_key_exists($req, $value)) {
                    $errors[] = "{$path}: missing required property '{$req}'";
                }
            }
            foreach ($schema['properties'] ?? [] as $prop => $propSchema) {
                if (array_key_exists($prop, $value)) {
                    $errors = [...$errors, ...$this->validateValue($value[$prop], $propSchema, "{$path}.{$prop}")];
                }
            }
        }

        if (is_array($value) && ($schema['type'] ?? '') === 'array' && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                $errors = [...$errors, ...$this->validateValue($item, $schema['items'], "{$path}[{$i}]")];
            }
        }

        return $errors;
    }
}

/**
 * Middleware that validates job args against a schema before processing.
 */
class SchemaValidationMiddleware implements Middleware
{
    public function __construct(
        private readonly SchemaValidator $validator,
    ) {}

    public function handle(JobContext $ctx, callable $next): mixed
    {
        $schemaUri = $ctx->job->schema;
        if ($schemaUri !== null) {
            $errors = $this->validator->validate($schemaUri, $ctx->job->args);
            if ($errors !== []) {
                throw new ValidationError(
                    "Schema validation failed: " . implode('; ', $errors),
                    'schema_validation_error',
                );
            }
        }
        return $next($ctx);
    }
}
