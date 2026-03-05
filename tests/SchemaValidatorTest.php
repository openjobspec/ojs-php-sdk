<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\{SchemaValidator, SchemaValidationMiddleware, JobContext, Job, ValidationError};
use PHPUnit\Framework\TestCase;

class SchemaValidatorTest extends TestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator();
        $this->validator->register('urn:ojs:email.send:v1', [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ]);
        $this->validator->register('urn:ojs:user.create:v1', [
            'type' => 'object',
            'required' => ['email', 'name'],
            'properties' => [
                'email' => ['type' => 'string', 'minLength' => 5],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'age' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 150],
            ],
        ]);
    }

    public function testValidateArraySchema(): void
    {
        $errors = $this->validator->validate('urn:ojs:email.send:v1', ['user@example.com', 'Welcome']);
        $this->assertEmpty($errors);
    }

    public function testValidateObjectSchema(): void
    {
        $errors = $this->validator->validate('urn:ojs:user.create:v1', [
            'email' => 'user@example.com',
            'name' => 'John Doe',
            'age' => 30,
        ]);
        $this->assertEmpty($errors);
    }

    public function testValidateMissingRequired(): void
    {
        $errors = $this->validator->validate('urn:ojs:user.create:v1', [
            'name' => 'John Doe',
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('email', $errors[0]);
    }

    public function testValidateWrongType(): void
    {
        $errors = $this->validator->validate('urn:ojs:user.create:v1', [
            'email' => 'user@example.com',
            'name' => 'John',
            'age' => 'thirty',
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('age', $errors[0]);
    }

    public function testValidateMinLength(): void
    {
        $errors = $this->validator->validate('urn:ojs:user.create:v1', [
            'email' => 'ab',
            'name' => 'J',
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('minLength', $errors[0]);
    }

    public function testValidateMinMax(): void
    {
        $errors = $this->validator->validate('urn:ojs:user.create:v1', [
            'email' => 'user@example.com',
            'name' => 'John',
            'age' => -1,
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('minimum', $errors[0]);
    }

    public function testValidateUnknownSchema(): void
    {
        $errors = $this->validator->validate('urn:ojs:unknown:v1', []);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not found', $errors[0]);
    }

    public function testIsValid(): void
    {
        $this->assertTrue($this->validator->isValid('urn:ojs:email.send:v1', ['test@test.com']));
        $this->assertFalse($this->validator->isValid('urn:ojs:user.create:v1', []));
    }

    public function testSchemaValidationMiddleware(): void
    {
        $middleware = new SchemaValidationMiddleware($this->validator);
        $ctx = new JobContext(Job::fromArray([
            'id' => 'sv-1',
            'type' => 'user.create',
            'args' => ['email' => 'user@example.com', 'name' => 'Jane'],
            'schema' => 'urn:ojs:user.create:v1',
            'state' => 'active',
        ]));

        $result = $middleware->handle($ctx, fn() => 'validated');
        $this->assertEquals('validated', $result);
    }

    public function testSchemaValidationMiddlewareRejects(): void
    {
        $middleware = new SchemaValidationMiddleware($this->validator);
        $ctx = new JobContext(Job::fromArray([
            'id' => 'sv-2',
            'type' => 'user.create',
            'args' => [],
            'schema' => 'urn:ojs:user.create:v1',
            'state' => 'active',
        ]));

        $this->expectException(ValidationError::class);
        $middleware->handle($ctx, fn() => 'should not reach');
    }

    public function testSchemaValidationMiddlewareSkipsWithoutSchema(): void
    {
        $middleware = new SchemaValidationMiddleware($this->validator);
        $ctx = new JobContext(Job::fromArray([
            'id' => 'sv-3',
            'type' => 'no.schema',
            'args' => ['anything'],
            'state' => 'active',
        ]));

        $result = $middleware->handle($ctx, fn() => 'skipped');
        $this->assertEquals('skipped', $result);
    }

    public function testValidateEnum(): void
    {
        $this->validator->register('urn:ojs:enum:v1', [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
            ],
        ]);

        $valid = $this->validator->validate('urn:ojs:enum:v1', ['status' => 'active']);
        $this->assertEmpty($valid);

        $invalid = $this->validator->validate('urn:ojs:enum:v1', ['status' => 'unknown']);
        $this->assertNotEmpty($invalid);
    }
}
