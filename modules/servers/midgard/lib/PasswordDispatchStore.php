<?php

declare(strict_types=1);

namespace MidgardWhmcs;

interface PasswordDispatchStore
{
    public function claimPasswordDispatch(int $serviceId, string $serverUuid): ?string;

    public function finalizePasswordDispatch(int $serviceId, string $dispatchHash): void;

    public function releasePasswordDispatch(string $dispatchHash): void;
}
