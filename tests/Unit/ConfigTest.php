<?php

declare(strict_types=1);

namespace {
    if (! function_exists('midgard_ConfigOptions')) {
        function midgard_ConfigOptions(): array
        {
            return [
                'location_id' => ['Type' => 'text'],
                'os_image_id' => ['Type' => 'text'],
                'cpu' => ['Type' => 'text'],
            ];
        }
    }
}

namespace MidgardWhmcs\Tests\Unit {

    use MidgardWhmcs\Config;
    use PHPUnit\Framework\TestCase;

    final class ConfigTest extends TestCase
    {
        public function test_int_option_resolves_direct_configoptions_key(): void
        {
            $params = [
                'configoptions' => [
                    'location_id' => '7',
                ],
            ];

            $this->assertSame(7, Config::intOption($params, 'location_id', 0));
        }

        public function test_int_option_resolves_normalized_configoptions_key(): void
        {
            $params = [
                'configoptions' => [
                    'Location ID' => '8',
                ],
            ];

            $this->assertSame(8, Config::intOption($params, 'location_id', 0));
        }

        public function test_int_option_resolves_configoption_n_fallback(): void
        {
            $params = [
                'configoption1' => '9',
            ];

            $this->assertSame(9, Config::intOption($params, 'location_id', 0));
        }

        public function test_int_option_uses_default_when_missing(): void
        {
            $params = [];

            $this->assertSame(12, Config::intOption($params, 'location_id', 12));
        }

        public function test_validate_critical_provisioning_ids_fails_for_unresolved_location(): void
        {
            $params = [
                'configoptions' => [
                    'os_image_id' => '16',
                ],
            ];

            $result = Config::validateCriticalProvisioningIds($params);

            $this->assertFalse($result['valid']);
            $this->assertArrayHasKey('location_id', $result['errors']);
        }

        public function test_validate_critical_provisioning_ids_passes_for_positive_values(): void
        {
            $params = [
                'configoptions' => [
                    'location_id' => '2',
                    'os_image_id' => '16',
                ],
            ];

            $result = Config::validateCriticalProvisioningIds($params);

            $this->assertTrue($result['valid']);
            $this->assertSame(2, $result['location_id']);
            $this->assertSame(16, $result['os_image_id']);
            $this->assertSame([], $result['errors']);
        }
    }
}
