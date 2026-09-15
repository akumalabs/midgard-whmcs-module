# Midgard WHMCS Module

Private WHMCS **Server Module** for provisioning and lifecycle management against Midgard API.

## Scope (v1)

- Preflight fail => WHMCS service remains `Pending`.
- `default_ipv4=true` is enforced at preflight/create (panel-side authority).
- Create accepted => WHMCS service becomes `Active` immediately.
- No WHMCS post-create IPv4 re-verification gate for fresh creates.
- Install fails later => WHMCS service remains `Active`; operational state is tracked in module metadata and shown in client area.
- Reuse is recovery-only:
  - `Active + midgard_server_id` => immediate success, no extra checks.
  - non-Active retry + `midgard_server_id` => sync existing server instead of duplicate create.
  - reuse 404 => stale mapping is cleared, then fresh create can proceed.
- Server naming is **always** from Midgard random-name API.
- Initial password is sent once via custom merge var `{$midgard_server_password}`.
- Module does **not** store secret in WHMCS `service_password`.

## Repository Layout

- `modules/servers/midgard/midgard.php` module entrypoint
- `modules/servers/midgard/lib/` Midgard client + sync + helpers
- `modules/servers/midgard/callback.php` build-completed webhook receiver (see below)
- `modules/servers/midgard/templates/clientarea.tpl` WHMCS-native overview replacement UI
- `modules/servers/midgard/hooks.php` cron sync hook
- `tests/` mapper/idempotency unit tests
- `.github/workflows/release.yml` versioned ZIP release

## Install

1. Build or download a tag artifact ZIP.
2. Extract ZIP into WHMCS root so this path exists:
   - `modules/servers/midgard/midgard.php`
3. In WHMCS admin:
   - Create/choose a server and set:
     - `Hostname`: Midgard panel base host (or full URL)
     - `Access Hash`: Midgard API bearer token
       - Admin token (default): plain token → all requests go to `/api/v1/admin`.
       - Reseller mode: prefix the token with the literal `reseller|`
         (`reseller|<token>`) → token is the remainder and every request
         goes to the scoped `/api/v1/reseller` surface instead.
     - Assign module `midgard` to target product.

## Product Module Settings

Set these in Module Settings:

- `location_id`
- `os_image_id`
- `cpu`
- `memory_gb`
- `disk_gb`
- `bandwidth_tb`
- `backup_limit`
- `snapshot_limit`
- `default_ipv4`
- `default_ipv6`
- `welcome_email_template`

## Metadata (Service)

Stored in module table `mod_midgard_service_meta`:

- `midgard_user_id`
- `midgard_server_id`
- `midgard_server_uuid`
- `midgard_provision_state`
- `midgard_last_error`
- `midgard_password_email_sent_at`

## Admin Rebind Workflow

- `Admin Services` tab exposes editable `Midgard Server ID`.
- Save with blank value => unbinds the service from current Midgard server mapping.
- Save with a value => validates server exists and owner matches stored `midgard_user_id` when available, then binds and syncs metadata.
- Use custom button `Refresh from Panel` to force sync server identity/network/state from Midgard.
- Rebinding is an admin recovery/operations flow; normal provisioning still uses `Create`.

## Email Merge Variable

Use in WHMCS email template body:

- `{$midgard_server_password}`

The module sends this var once via `SendEmail` API. A dispatch idempotency key (`service_id + server_uuid`) prevents duplicate/cross-send under concurrency.

## Cron Sync

`hooks.php` registers `AfterCronJob` hook to:

- refresh install state (`installing|ready|failed`)
- sync panel rename (`name`/`hostname`) into WHMCS service identity fields
- keep operational error detail up to date
- flush queued one-time password emails (delivery safety net — see below)

## Build-Completed Webhook (Callback)

`TestConnection` and `CreateAccount` register this WHMCS install with the
panel (`POST /webhook-registration {url, secret}`, idempotent — the 64-hex
secret and the "registered" flag live in `mod_midgard_install_settings`).
Once registered, the panel pushes `server.build.completed` to
`modules/servers/midgard/callback.php`:

- `X-Midgard-Signature: sha256=hmac_sha256(timestamp . "." . rawBody, secret)`,
  verified with `hash_equals` and a ±300s timestamp window (401 otherwise).
- `data.server_id` maps to module meta `midgard_server_id` (a shared panel
  server may back several services — all candidates are processed).
- The same live gate as the cron flush worker (`SyncService::credentialsEmailGate`)
  must report the server `ready` before the email goes out, through the same
  `PasswordMailer::sendOneTime` async path.
- Responses are always JSON: `200 {status: sent|already_sent|not_ready|no_service}`;
  401 only for bad signature/stale timestamp, 500 for internal errors.
- The `AfterCronJob` flush worker stays fully operational as the delivery
  safety net — the webhook only makes delivery faster.

## Tag + Release

- Tag format: `v0.x.y`
- Push tag to trigger release workflow
- Output: `midgard-whmcs-module-v0.x.y.zip`

## Local Tests

```bash
composer install
composer test
```

## Troubleshooting

- `TestConnection` fails: check server host/token.
- `CreateAccount` fails with preflight message: verify node resources/IP availability.
- Manual bind rejected: verify server ID exists in Midgard and belongs to expected user.
- No SSO button: ensure module metadata has `midgard_server_uuid` and Midgard SSO endpoint is available.
