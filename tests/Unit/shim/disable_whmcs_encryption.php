<?php

/*
 * Simulates a WHMCS boot WITHOUT a working encryption layer. PHP cannot
 * undefine the encrypt() shim, so this flips a global flag the shim honours:
 * encrypt() then returns false exactly like a WHMCS install without a
 * configured cipher, driving PasswordMailer::queue() into its refusal branch
 * (no 'plain:' blob may ever be persisted — Master-locked 2026-10-01).
 */
$GLOBALS['__WHMCS_ENCRYPT_DISABLED'] = true;
