<?php

declare(strict_types=1);

namespace MidgardWhmcs;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Hides the native WHMCS "Dedicated IP" / "Assigned IPs" rows from the
 * CLIENT AREA product details page only.
 *
 * Why: the module template already renders IPv4/IPv6 in its spec grid
 * (live values from panel metadata). The native rows would duplicate the
 * data on the same page. The DATABASE columns stay live — admins keep
 * product details + search-by-IP in the admin service lists.
 *
 * CONTRACT (learned the hard way, 2026-10-02): ClientAreaPage* hook
 * responses are MERGED into the template variables — a key absent from the
 * returned array keeps its original value, so unset() here is a silent
 * no-op and the row still renders. Removal works by OVERRIDING the keys
 * with an empty string: the six-style templates guard the rows with
 * {if $dedicatedip} / {if $assignedips}, so '' hides them.
 *
 * Scope: only services whose product servertype = 'midgard'. Nothing in
 * the DB is touched.
 */
final class ClientAreaDisplay
{
    /** Display variables WHMCS maps from the native hosting columns. */
    private const HIDDEN_DISPLAY_VARS = ['dedicatedip', 'assignedips'];

    /**
     * @param array<string, mixed> $vars Template variables for the page.
     *
     * @return array<string, mixed> Overrides that blank the midgard native IP rows.
     */
    public static function filterProductDetailsVars(array $vars): array
    {
        if (! self::isMidgardServiceContext($vars)) {
            return [];
        }

        // Merge semantics: returning '' OVERRIDES the template var (unset
        // would leave the original value in place and the row visible).
        return array_fill_keys(self::HIDDEN_DISPLAY_VARS, '');
    }

    /**
     * The hook fires for every product/service page; act only when the page
     * belongs to a product with servertype 'midgard'.
     *
     * @param array<string, mixed> $vars
     */
    private static function isMidgardServiceContext(array $vars): bool
    {
        $candidates = array_values(array_filter(array_unique(array_map(
            static fn ($key): int => (int) ($vars[$key] ?? 0),
            ['serviceid', 'id', 'relid']
        )), static fn (int $id): bool => $id > 0));

        if ($candidates === []) {
            return false;
        }

        return Capsule::table('tblhosting')
            ->leftJoin('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
            ->where('tblproducts.servertype', 'midgard')
            ->whereIn('tblhosting.id', $candidates)
            ->exists();
    }
}
