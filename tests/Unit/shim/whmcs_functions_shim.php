<?php

/*
 * Global WHMCS function shims for the unit-test environment.
 * PasswordMailer::seal() prefers WHMCS's own encrypt()/decrypt() helpers.
 * They only exist inside a full WHMCS boot, so tests would otherwise only
 * ever exercise a fallback path. These shims provide a reversible stand-in
 * pair so the sealed 'enc:' path is covered for real. Tests that need to
 * simulate a broken/absent cipher set $GLOBALS['__WHMCS_ENCRYPT_DISABLED']
 * (see disable_whmcs_encryption.php) and encrypt() returns false — the
 * shape that drives the queue() refusal branch.
 */

require __DIR__.'/../../../vendor/autoload.php';

if (! function_exists('encrypt')) {
    function encrypt($value)
    {
        if (! empty($GLOBALS['__WHMCS_ENCRYPT_DISABLED'])) {
            return false;
        }

        return 'WHMCS-ENC::'.$value;
    }
}
if (! function_exists('decrypt')) {
    function decrypt($value)
    {
        if (! is_string($value) || ! str_starts_with($value, 'WHMCS-ENC::')) {
            return '';
        }

        return substr($value, 11);
    }
}
