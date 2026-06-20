<?php

declare(strict_types=1);

namespace MidgardWhmcs;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * Caches Midgard panel catalog data (locations, OS images) in the WHMCS
 * database so that Module Settings dropdowns and Configurable Options can
 * be populated without a live API call during product setup.
 */
final class CatalogCache
{
    private const TABLE = 'midgard_catalog_cache';
    private const KEY_LOCATIONS = 'locations';
    private const KEY_OS_IMAGES = 'os_images';

    /**
     * Ensure the cache table exists. Idempotent.
     */
    public static function ensureTable(): void
    {
        if (! Capsule::schema()->hasTable(self::TABLE)) {
            Capsule::schema()->create(self::TABLE, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('cache_key', 64)->unique();
                $table->longText('value')->nullable();
                $table->timestamp('updated_at')->useCurrent();
            });
        }
    }

    /**
     * Fetch locations and OS images from the panel and store them.
     *
     * @return array{locations: array<int, array{id: int, label: string}>, os_images: array<int, array{id: int, label: string}>}
     */
    public static function refresh(ApiClient $client): array
    {
        $locationsRaw = $client->getLocations();
        $osImagesRaw  = $client->getOsImages();

        $locations = self::normalizeLocations($locationsRaw['data'] ?? []);
        $osImages  = self::normalizeOsImages($osImagesRaw['data'] ?? []);

        self::write(self::KEY_LOCATIONS, $locations);
        self::write(self::KEY_OS_IMAGES, $osImages);

        return [
            'locations' => $locations,
            'os_images' => $osImages,
        ];
    }

    /**
     * @return array<int, array{id: int, label: string}>
     */
    public static function getLocations(): array
    {
        return self::read(self::KEY_LOCATIONS);
    }

    /**
     * @return array<int, array{id: int, label: string}>
     */
    public static function getOsImages(): array
    {
        return self::read(self::KEY_OS_IMAGES);
    }

    /**
     * Return WHMCS dropdown options for locations.
     * Each entry is "ID|Display Name".
     *
     * @return list<string>
     */
    public static function getLocationDropdownOptions(): array
    {
        return self::toDropdownOptions(self::getLocations());
    }

    /**
     * Return WHMCS dropdown options for OS images.
     * Each entry is "ID|Display Name".
     *
     * @return list<string>
     */
    public static function getOsImageDropdownOptions(): array
    {
        return self::toDropdownOptions(self::getOsImages());
    }

    // ------------------------------------------------------------------
    //  Internal helpers
    // ------------------------------------------------------------------

    /**
     * @param array<int, array{id: int, label: string}> $items
     * @return list<string>
     */
    private static function toDropdownOptions(array $items): array
    {
        $options = [];
        foreach ($items as $item) {
            $id    = (int) ($item['id'] ?? 0);
            $label = trim((string) ($item['label'] ?? ''));
            if ($id > 0 && $label !== '') {
                $options[] = $id . '|' . $label;
            }
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<int, array{id: int, label: string}>
     */
    private static function normalizeLocations(array $raw): array
    {
        $result = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id   = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            $code = trim((string) ($row['short_code'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }

            $label = $code !== '' ? "{$name} ({$code})" : $name;
            $result[] = ['id' => $id, 'label' => $label];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<int, array{id: int, label: string}>
     */
    private static function normalizeOsImages(array $raw): array
    {
        $result = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id   = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }

            $distro  = trim((string) ($row['distro'] ?? ''));
            $version = trim((string) ($row['version'] ?? ''));
            $arch    = trim((string) ($row['architecture'] ?? ''));

            $parts = array_filter([$distro, $version, $arch], fn ($p) => $p !== '');
            $detail = implode(' ', $parts);

            $label = $detail !== '' ? "{$name} ({$detail})" : $name;
            $result[] = ['id' => $id, 'label' => $label];
        }

        return $result;
    }

    /**
     * @return array<int, array{id: int, label: string}>
     */
    private static function read(string $key): array
    {
        self::ensureTable();

        $json = Capsule::table(self::TABLE)
            ->where('cache_key', $key)
            ->value('value');

        if ($json === null) {
            return [];
        }

        $decoded = json_decode((string) $json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $result[] = [
                'id'    => $id,
                'label' => (string) ($row['label'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array{id: int, label: string}> $value
     */
    private static function write(string $key, array $value): void
    {
        self::ensureTable();

        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '[]';
        }

        $existing = Capsule::table(self::TABLE)
            ->where('cache_key', $key)
            ->exists();

        if ($existing) {
            Capsule::table(self::TABLE)
                ->where('cache_key', $key)
                ->update([
                    'value'      => $json,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        } else {
            Capsule::table(self::TABLE)->insert([
                'cache_key'  => $key,
                'value'      => $json,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
