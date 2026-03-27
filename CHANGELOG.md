# Changelog

## Unreleased

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
