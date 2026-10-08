<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use MidgardWhmcs\ApiClient;
use MidgardWhmcs\MidgardApiException;
use PHPUnit\Framework\TestCase;

/**
 * Terminate idempotency contract (2026-10-08 kuroit incident aftermath).
 *
 * A Terminate whose panel HTTP call timed out destroyed the server anyway;
 * WHMCS still shows the service Active, so the operator retries Terminate —
 * but the panel row is GONE, the panel answers 404, and without an
 * idempotency rule that retry fails FOREVER (the service can never be
 * terminated in WHMCS).
 *
 * Contract: ApiClient::isGone() is true ONLY for a 404 MidgardApiException —
 * the panel's "server already destroyed" answer. Every other status
 * (5xx, timeouts with code 0, non-API exceptions) must stay a failure so
 * real breakage is never swallowed.
 */
final class TerminateIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The module ships MidgardApiException inside ApiClient.php (one
        // file, two classes): touching ApiClient loads BOTH. Production
        // always news ApiClient before the exception can be thrown.
        class_exists(ApiClient::class, true);
    }

    public function test_404_api_exception_is_gone(): void
    {
        $e = new MidgardApiException('No query results for model [Server] 27.', 404);

        $this->assertTrue(ApiClient::isGone($e));
    }

    public function test_other_api_statuses_are_not_gone(): void
    {
        $this->assertFalse(ApiClient::isGone(new MidgardApiException('boom', 500)));
        $this->assertFalse(ApiClient::isGone(new MidgardApiException('boom', 422)));
        $this->assertFalse(ApiClient::isGone(new MidgardApiException('HTTP request failed: timeout', 0)));
    }

    public function test_non_api_exception_is_not_gone(): void
    {
        $this->assertFalse(ApiClient::isGone(new \RuntimeException('404-ish text')));
    }
}
