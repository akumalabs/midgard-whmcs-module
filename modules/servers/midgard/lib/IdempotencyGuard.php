<?php

declare(strict_types=1);

namespace MidgardWhmcs;

final class IdempotencyGuard
{
    public static function buildDispatchKey(int $serviceId, string $serverUuid): string
    {
        return $serviceId . ':' . strtolower(trim($serverUuid));
    }

    public static function hash(string $dispatchKey): string
    {
        return hash('sha256', $dispatchKey);
    }
}
