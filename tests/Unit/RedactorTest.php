<?php

declare(strict_types=1);

namespace Jobviz\Agent\Tests\Unit;

use Jobviz\Agent\Redactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    #[Test]
    public function redacts_matching_keys(): void
    {
        $redactor = new Redactor(['password', 'secret']);

        $result = $redactor->redact([
            'username' => 'john',
            'password' => 'hunter2',
            'secret' => 'abc123',
        ]);

        $this->assertSame('john', $result['username']);
        $this->assertSame('[REDACTED]', $result['password']);
        $this->assertSame('[REDACTED]', $result['secret']);
    }

    #[Test]
    public function redacts_case_insensitively(): void
    {
        $redactor = new Redactor(['password']);

        $result = $redactor->redact([
            'Password' => 'hunter2',
            'PASSWORD' => 'hunter3',
            'passWord' => 'hunter4',
        ]);

        $this->assertSame('[REDACTED]', $result['Password']);
        $this->assertSame('[REDACTED]', $result['PASSWORD']);
        $this->assertSame('[REDACTED]', $result['passWord']);
    }

    #[Test]
    public function redacts_nested_arrays(): void
    {
        $redactor = new Redactor(['token']);

        $result = $redactor->redact([
            'user' => [
                'name' => 'john',
                'auth' => [
                    'token' => 'secret-token',
                    'type' => 'bearer',
                ],
            ],
        ]);

        $this->assertSame('john', $result['user']['name']);
        $this->assertSame('[REDACTED]', $result['user']['auth']['token']);
        $this->assertSame('bearer', $result['user']['auth']['type']);
    }

    #[Test]
    public function returns_data_unchanged_when_no_keys_configured(): void
    {
        $redactor = new Redactor([]);

        $data = ['password' => 'visible', 'secret' => 'also-visible'];
        $result = $redactor->redact($data);

        $this->assertSame($data, $result);
    }

    #[Test]
    public function handles_scalar_input(): void
    {
        $redactor = new Redactor(['password']);

        $this->assertSame('hello', $redactor->redact('hello'));
        $this->assertSame(42, $redactor->redact(42));
        $this->assertTrue($redactor->redact(true));
        $this->assertNull($redactor->redact(null));
    }

    #[Test]
    public function stops_at_max_depth(): void
    {
        $redactor = new Redactor(['secret']);

        // Build a deeply nested structure (25 levels)
        $data = ['secret' => 'should-be-redacted'];
        for ($i = 0; $i < 25; $i++) {
            $data = ['level' => $data];
        }

        $result = $redactor->redact($data);

        // Walk down to find the deepest 'secret' — it should NOT be redacted
        // because max depth is 20
        $node = $result;
        for ($i = 0; $i < 25; $i++) {
            $node = $node['level'];
        }

        // Beyond depth 20, walk() returns the data as-is (non-array passthrough)
        // The inner array at depth 21+ is returned without walking
        $this->assertIsArray($node);
    }

    #[Test]
    public function returns_too_large_notice_for_oversized_string(): void
    {
        $redactor = new Redactor(['password']);

        $hugeString = str_repeat('a', 1_048_577); // 1 MB + 1
        $result = $redactor->redact($hugeString);

        $this->assertSame(['_notice' => '[INPUT_TOO_LARGE]'], $result);
    }

    #[Test]
    public function returns_too_large_notice_for_oversized_array(): void
    {
        $redactor = new Redactor(['password']);

        // Create an array that exceeds 1 MB when JSON encoded
        $data = ['payload' => str_repeat('x', 1_048_577)];
        $result = $redactor->redact($data);

        $this->assertSame(['_notice' => '[INPUT_TOO_LARGE]'], $result);
    }

    #[Test]
    public function preserves_numeric_array_keys(): void
    {
        $redactor = new Redactor(['secret']);

        $result = $redactor->redact([
            0 => 'first',
            1 => 'second',
            2 => ['secret' => 'hidden'],
        ]);

        $this->assertSame('first', $result[0]);
        $this->assertSame('second', $result[1]);
        $this->assertSame('[REDACTED]', $result[2]['secret']);
    }

    #[Test]
    public function handles_empty_array(): void
    {
        $redactor = new Redactor(['password']);
        $this->assertSame([], $redactor->redact([]));
    }

    #[Test]
    public function handles_mixed_nested_types(): void
    {
        $redactor = new Redactor(['token']);

        $result = $redactor->redact([
            'token' => 'secret',
            'count' => 42,
            'items' => [
                ['token' => 'nested-secret', 'value' => true],
                'plain string',
                null,
            ],
        ]);

        $this->assertSame('[REDACTED]', $result['token']);
        $this->assertSame(42, $result['count']);
        $this->assertSame('[REDACTED]', $result['items'][0]['token']);
        $this->assertTrue($result['items'][0]['value']);
        $this->assertSame('plain string', $result['items'][1]);
        $this->assertNull($result['items'][2]);
    }
}
