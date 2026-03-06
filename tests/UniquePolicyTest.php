<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\{UniquePolicy, ValidationError};
use PHPUnit\Framework\TestCase;

class UniquePolicyTest extends TestCase
{
    // ── Construction ────────────────────────────────────────

    public function testConstructorDefaults(): void
    {
        $policy = new UniquePolicy();

        $this->assertEquals(['type'], $policy->keys);
        $this->assertEquals([], $policy->argsKeys);
        $this->assertEquals([], $policy->metaKeys);
        $this->assertNull($policy->period);
        $this->assertEquals(['available', 'active', 'scheduled', 'retryable', 'pending'], $policy->states);
        $this->assertEquals('reject', $policy->onConflict);
    }

    public function testConstructorCustomValues(): void
    {
        $policy = new UniquePolicy(
            keys: ['type', 'queue'],
            argsKeys: ['user_id', 'region'],
            metaKeys: ['tenant'],
            period: 'PT2H',
            states: ['available', 'active'],
            onConflict: 'replace',
        );

        $this->assertEquals(['type', 'queue'], $policy->keys);
        $this->assertEquals(['user_id', 'region'], $policy->argsKeys);
        $this->assertEquals(['tenant'], $policy->metaKeys);
        $this->assertEquals('PT2H', $policy->period);
        $this->assertEquals(['available', 'active'], $policy->states);
        $this->assertEquals('replace', $policy->onConflict);
    }

    // ── Validation ──────────────────────────────────────────

    public function testValidOnConflictReject(): void
    {
        $policy = new UniquePolicy(onConflict: 'reject');
        $this->assertEquals('reject', $policy->onConflict);
    }

    public function testValidOnConflictReplace(): void
    {
        $policy = new UniquePolicy(onConflict: 'replace');
        $this->assertEquals('replace', $policy->onConflict);
    }

    public function testValidOnConflictReplaceExceptSchedule(): void
    {
        $policy = new UniquePolicy(onConflict: 'replace_except_schedule');
        $this->assertEquals('replace_except_schedule', $policy->onConflict);
    }

    public function testValidOnConflictIgnore(): void
    {
        $policy = new UniquePolicy(onConflict: 'ignore');
        $this->assertEquals('ignore', $policy->onConflict);
    }

    public function testInvalidOnConflictThrowsValidationError(): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid on_conflict value');
        new UniquePolicy(onConflict: 'invalid');
    }

    public function testInvalidOnConflictEmptyString(): void
    {
        $this->expectException(ValidationError::class);
        new UniquePolicy(onConflict: '');
    }

    public function testInvalidOnConflictCaseSensitive(): void
    {
        $this->expectException(ValidationError::class);
        new UniquePolicy(onConflict: 'Reject');
    }

    // ── toArray ─────────────────────────────────────────────

    public function testToArrayMinimalDefaults(): void
    {
        $policy = new UniquePolicy();
        $arr = $policy->toArray();

        $this->assertEquals(['type'], $arr['keys']);
        $this->assertEquals('reject', $arr['on_conflict']);
        $this->assertArrayNotHasKey('args_keys', $arr);
        $this->assertArrayNotHasKey('meta_keys', $arr);
        $this->assertArrayNotHasKey('period', $arr);
        $this->assertArrayNotHasKey('states', $arr);
    }

    public function testToArrayWithOptionalFields(): void
    {
        $policy = new UniquePolicy(
            keys: ['type', 'args'],
            argsKeys: ['email'],
            metaKeys: ['org_id'],
            period: '1h',
            states: ['available'],
            onConflict: 'ignore',
        );

        $arr = $policy->toArray();

        $this->assertEquals(['type', 'args'], $arr['keys']);
        $this->assertEquals(['email'], $arr['args_keys']);
        $this->assertEquals(['org_id'], $arr['meta_keys']);
        $this->assertEquals('1h', $arr['period']);
        $this->assertEquals(['available'], $arr['states']);
        $this->assertEquals('ignore', $arr['on_conflict']);
    }

    public function testToArrayOmitsDefaultStates(): void
    {
        $policy = new UniquePolicy(
            states: ['available', 'active', 'scheduled', 'retryable', 'pending'],
        );
        $arr = $policy->toArray();

        $this->assertArrayNotHasKey('states', $arr);
    }

    public function testToArrayIncludesNonDefaultStates(): void
    {
        $policy = new UniquePolicy(states: ['active']);
        $arr = $policy->toArray();

        $this->assertArrayHasKey('states', $arr);
        $this->assertEquals(['active'], $arr['states']);
    }

    public function testToArrayOmitsEmptyArgsKeys(): void
    {
        $policy = new UniquePolicy(argsKeys: []);
        $this->assertArrayNotHasKey('args_keys', $policy->toArray());
    }

    public function testToArrayOmitsEmptyMetaKeys(): void
    {
        $policy = new UniquePolicy(metaKeys: []);
        $this->assertArrayNotHasKey('meta_keys', $policy->toArray());
    }

    public function testToArrayOmitsNullPeriod(): void
    {
        $policy = new UniquePolicy(period: null);
        $this->assertArrayNotHasKey('period', $policy->toArray());
    }

    // ── fromArray ───────────────────────────────────────────

    public function testFromArrayMinimal(): void
    {
        $policy = UniquePolicy::fromArray([]);

        $this->assertEquals(['type'], $policy->keys);
        $this->assertEquals([], $policy->argsKeys);
        $this->assertEquals([], $policy->metaKeys);
        $this->assertNull($policy->period);
        $this->assertEquals('reject', $policy->onConflict);
    }

    public function testFromArrayWithAllFields(): void
    {
        $policy = UniquePolicy::fromArray([
            'keys' => ['type', 'queue'],
            'args_keys' => ['user_id'],
            'meta_keys' => ['tenant'],
            'period' => 'PT30M',
            'states' => ['available', 'active'],
            'on_conflict' => 'replace',
        ]);

        $this->assertEquals(['type', 'queue'], $policy->keys);
        $this->assertEquals(['user_id'], $policy->argsKeys);
        $this->assertEquals(['tenant'], $policy->metaKeys);
        $this->assertEquals('PT30M', $policy->period);
        $this->assertEquals(['available', 'active'], $policy->states);
        $this->assertEquals('replace', $policy->onConflict);
    }

    public function testFromArrayInvalidConflictThrows(): void
    {
        $this->expectException(ValidationError::class);
        UniquePolicy::fromArray(['on_conflict' => 'bad_value']);
    }

    // ── Roundtrip ───────────────────────────────────────────

    public function testSerializationRoundtripDefaults(): void
    {
        $original = new UniquePolicy();
        $restored = UniquePolicy::fromArray($original->toArray());

        $this->assertEquals($original->keys, $restored->keys);
        $this->assertEquals($original->onConflict, $restored->onConflict);
        $this->assertEquals($original->argsKeys, $restored->argsKeys);
        $this->assertEquals($original->metaKeys, $restored->metaKeys);
        $this->assertEquals($original->period, $restored->period);
    }

    public function testSerializationRoundtripCustom(): void
    {
        $original = new UniquePolicy(
            keys: ['type', 'args', 'meta'],
            argsKeys: ['email', 'action'],
            metaKeys: ['org'],
            period: 'PT6H',
            states: ['scheduled'],
            onConflict: 'replace_except_schedule',
        );

        $restored = UniquePolicy::fromArray($original->toArray());

        $this->assertEquals($original->keys, $restored->keys);
        $this->assertEquals($original->argsKeys, $restored->argsKeys);
        $this->assertEquals($original->metaKeys, $restored->metaKeys);
        $this->assertEquals($original->period, $restored->period);
        $this->assertEquals($original->states, $restored->states);
        $this->assertEquals($original->onConflict, $restored->onConflict);
    }
}
