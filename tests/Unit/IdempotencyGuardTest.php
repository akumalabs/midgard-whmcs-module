<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use MidgardWhmcs\IdempotencyGuard;
use PHPUnit\Framework\TestCase;

final class IdempotencyGuardTest extends TestCase
{
    public function test_build_dispatch_key_is_stable(): void
    {
        $keyA = IdempotencyGuard::buildDispatchKey(101, 'ABC-DEF');
        $keyB = IdempotencyGuard::buildDispatchKey(101, 'abc-def');

        $this->assertSame('101:abc-def', $keyA);
        $this->assertSame($keyA, $keyB);
    }

    public function test_hash_changes_between_services(): void
    {
        $hashA = IdempotencyGuard::hash(IdempotencyGuard::buildDispatchKey(1, 'server-uuid'));
        $hashB = IdempotencyGuard::hash(IdempotencyGuard::buildDispatchKey(2, 'server-uuid'));

        $this->assertNotSame($hashA, $hashB);
    }
}
