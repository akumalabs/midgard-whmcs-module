# Changelog

## Unreleased

- **Fix: one-time credentials email never sent (queue silently lost the password).** `MetadataStore::upsert()` had no `midgard_pending_password` column in its schema, in `get()`, or in its write payload — so `PasswordMailer::queue()` sealed the password into the meta array and the blob was silently dropped on write. The cron worker then found nothing to unseal, returned silently, and burned all 5 queue attempts with `last_error` never recorded (prod incident 2026-09-14, services 155/156/157). The column now exists end-to-end (create + additive migration + get + upsert) and regression tests prove the sealed blob survives read-modify-write cycles. Both silent-return paths in `sendOneTime()` (async, dispatch-hash owned) now throw so failures are visible in `last_error` + WHMCS Module Logs.
- **Fix: password generator could leak ambiguous characters.** The class-guarantee guards drew digits via `random_int(0,9)` (can inject `0`/`1`) and letters via `chr()` (can inject `I`/`O`/`l`) — bypassing the ambiguity-free alphabet that deliberately excludes them. Guards now draw from the same ambiguity-free classes.

- **Fix: credentials email could be silently stranded forever.** `claimPasswordDispatch()` returned null on a duplicate dispatch key — if a claim row survived from an interrupted attempt (e.g. a 504'd create), every later `queue()` call aborted without sealing the password or marking the row queued, so the cron worker skipped it indefinitely. Duplicate claims now recycle the row: previously-sent rows re-arm for a new email, stuck (claimed-never-queued) rows re-arm with attempts/errors reset, and already-queued unsent rows keep worker state. Covered by real-sqlite lifecycle tests.
- Diagnostic log entry `passwordEmailQueued` written when a dispatch is queued (visible in WHMCS Module Logs).

- **Password generator: first character is now always alphanumeric.** The password is embedded by PVE into cloud-init user-data YAML; a leading YAML indicator (`@`, `|`, `>`, `` ` ``, …) could break cloudbase-init's parse (UserDataPlugin ScannerError) and the VM booted with no working password. Guard replacements also never touch position 0 anymore. Panel-side: new `App\Rules\CloudInitSafePassword` validation (matching fix for client-entered create/reinstall passwords).
- **Async credentials email (fix WHMCS-side 504):** `CreateAccount` no longer sends the one-time password email synchronously. `PasswordMailer::queue()` seals the password into service metadata (WHMCS `encrypt()` when available, reversible base64 fallback) and marks the dispatch row queued; the WHMCS cron worker (`AfterCronJob` → `midgard_cronFlushQueuedPasswordEmails`) delivers it with retry (attempt cap 5, `last_error` recorded per row). A slow SMTP server can no longer push provisioning past the PHP-FPM limit, and a failed send can no longer return an error AFTER the server was created (old path set the service back to Pending post-create). Queue failure itself is non-fatal: service still activates, warning recorded in `midgard_last_error`.
- MetadataStore: additive lazy migration for `mod_midgard_email_dispatch` (`queued_at`, `queue_attempts`, `last_error`) + queue accessors (`queuePasswordDispatch`, `pendingPasswordDispatches`, `pendingDispatchForService`, …).
- `PasswordMailer::sendOneTime()` gains an optional `$dispatchHash` param: cron borrows the queued row (kept on failure for retry), sync callers keep the old claim/release behavior. Sealed password is wiped from metadata after successful delivery.
- Cron email flush resolves the WHMCS client id from `tblhosting.userid` (meta `midgard_user_id` is the PANEL user id — never valid as a localAPI userid).
- Switched create flow to recovery-only reuse semantics (`Active + server_id` short-circuit, non-Active reuse sync path, 404 stale mapping self-heal).
- Removed WHMCS post-create IPv4 verification gate for fresh creates; strict IPv4 remains preflight/create responsibility.
- Added admin bind/unbind workflow for `midgard_server_id` via `AdminServicesTabFieldsSave` with existence/owner validation.
- Added admin custom action `Refresh from Panel` for explicit metadata sync.
- Expanded operator README with rebind workflow and updated provisioning behavior notes.

## v0.1.0

- Bootstrap WHMCS server module structure (`midgard`)
- Added create/suspend/unsuspend/terminate/change-package actions
- Added preflight-first create flow with random-name enforced
- Added one-time password email dispatch using `{$midgard_server_password}`
- Added operational provisioning state sync and client-area status/spec/SSO blocks
- Added release workflow to build installable ZIP artifacts on tags
