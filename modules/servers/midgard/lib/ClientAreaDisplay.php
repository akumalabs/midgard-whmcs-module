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
 * Scope: only services whose product servertype = 'midgard'. Only the
 * display variables of the client area page; nothing in the DB is touched.
 */
final class ClientAreaDisplay
{
    /** Display variables WHMCS maps from the native hosting columns. */
    private const HIDDEN_DISPLAY_VARS = ['dedicatedip', 'assignedips'];

    /**
     * @param array<string, mixed> $vars Template variables for the page.
     *
     * @return array<string, mixed> Variables with midgard native IP rows removed.
     */
    public static function filterProductDetailsVars(array $vars): array
    {
        if (! self::isMidgardServiceContext($vars)) {
            return $vars;
        }

        foreach (self::HIDDEN_DISPLAY_VARS as $key) {
            unset($vars[$key]);
        }

        return $vars;
    }

    /**
     * The hook fires for every product/service page; act only when the page
     * belongs to a product with servertype 'midgard'.
     *
     * @param array<string, mixed> $vars
     */
    private static function isMidgardServiceContext(array $vars): bool
    {
        $serviceId = (int) ($vars['serviceid'] ?? 0);
        if ($serviceId <= 0) {
            return false;
        }

        return Capsule::table('tblhosting')
            ->leftJoin('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
            ->where('tblproducts.servertype', 'midgard')
            ->where('tblhosting.id', $serviceId)
            ->exists();
    }
}
