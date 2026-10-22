<div class="midgard-clientarea midgard-ca" id="midgard-ca"
     data-actions-enabled="{$midgardActionsEnabled|default:0}"
     data-ajax-url="{$midgardAjaxUrl|default:''|escape}"
     data-csrf="{$midgardCsrf|default:''|escape}"
     data-service-id="{$midgardServerId|default:0}"
     data-status="{$midgardRuntimeStatus|default:''|escape}">
    <style>
        .midgard-clientarea.midgard-ca {
            --mg-surface: #ffffff;
            --mg-subtle: #fafafa;
            --mg-border: #e4e4e7;
            --mg-border-strong: #d4d4d8;
            --mg-text: #18181b;
            --mg-muted: #71717a;
            --mg-faint: #a1a1aa;
            --mg-indigo: #6366f1;
            --mg-indigo-soft: #4f46e5;
            --mg-success: #16a34a;
            --mg-danger: #dc2626;
            --mg-danger-hover: #ef4444;
            --mg-warning: #ca8a04;
            background: var(--mg-surface);
            border: 1px solid var(--mg-border);
            border-radius: 12px;
            color: var(--mg-text);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            padding: 24px;
            /* WHMCS themes like to center this block — everything stays left-aligned. */
            text-align: left;
        }

        .midgard-clientarea.midgard-ca *,
        .midgard-clientarea.midgard-ca *::before,
        .midgard-clientarea.midgard-ca *::after { box-sizing: border-box; }

        /* Primary CSS kill: hide pre-content siblings in wrappers that directly host Midgard block. */
        :where(.tab-pane, .tab-content, .module-client-area, .moduleclientarea, .panel-body):has(> .midgard-clientarea) > :not(.midgard-clientarea):not(script):not(style) {
            display: none !important;
        }

        /* ── Header ─────────────────────────────────────────────────── */
        .midgard-ca-header { align-items: center; display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; margin-bottom: 20px; }
        .midgard-ca-title { color: var(--mg-text); font-size: 20px; font-weight: 600; letter-spacing: -0.01em; line-height: 1.3; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        /* Status pulse (panel parity) */
        .midgard-clientarea .midgard-header-status { align-items: center; display: inline-flex; flex: 0 0 auto; gap: 7px; }
        .midgard-clientarea .midgard-status-dot { border-radius: 50%; display: inline-block; height: 8px; width: 8px; }
        .midgard-clientarea .midgard-status-text { font-size: 11px; font-weight: 600; letter-spacing: 0.05em; line-height: 1; text-transform: uppercase; }
        .midgard-clientarea .midgard-status-state-success { color: var(--mg-success); }
        .midgard-clientarea .midgard-status-state-success .midgard-status-dot { background: var(--mg-success); animation: midgard-pulse-green 1.6s infinite; }
        .midgard-clientarea .midgard-status-state-warning { color: var(--mg-warning); }
        .midgard-clientarea .midgard-status-state-warning .midgard-status-dot { background: var(--mg-warning); animation: midgard-pulse-yellow 1.6s infinite; }
        .midgard-clientarea .midgard-status-state-danger { color: var(--mg-danger); }
        .midgard-clientarea .midgard-status-state-danger .midgard-status-dot { background: var(--mg-danger); animation: midgard-pulse-red 1.6s infinite; }
        .midgard-clientarea .midgard-status-state-suspended { color: #7c3aed; }
        .midgard-clientarea .midgard-status-state-suspended .midgard-status-dot { background: #7c3aed; animation: midgard-pulse-purple 1.6s infinite; }
        .midgard-clientarea .midgard-status-state-default { color: var(--mg-faint); }
        .midgard-clientarea .midgard-status-state-default .midgard-status-dot { background: var(--mg-faint); animation: midgard-pulse-gray 1.6s infinite; }

        /* ── Action bar ─────────────────────────────────────────────── */
        .midgard-ca-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; }
        .midgard-ca-actions .mg-sep { background: var(--mg-border); height: 22px; margin: 0 2px; width: 1px; }
        .mg-icon-btn {
            align-items: center;
            background: transparent;
            border: 1px solid var(--mg-border-strong);
            border-radius: 8px;
            color: var(--mg-muted);
            cursor: pointer;
            display: inline-flex;
            height: 38px;
            justify-content: center;
            transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
            width: 52px;
        }
        .mg-icon-btn svg { height: 18px; width: 18px; }
        .mg-icon-btn:disabled { cursor: not-allowed; opacity: 0.45; }
        .mg-icon-btn.mg-start { border-color: rgba(22, 163, 74, 0.4); color: var(--mg-success); }
        .mg-icon-btn.mg-start:hover:not(:disabled) { background: rgba(22, 163, 74, 0.08); }
        .mg-icon-btn.mg-stop { border-color: rgba(220, 38, 38, 0.4); color: var(--mg-danger); }
        .mg-icon-btn.mg-stop:hover:not(:disabled) { background: rgba(220, 38, 38, 0.08); }
        .mg-icon-btn.mg-restart { border-color: rgba(202, 138, 4, 0.45); color: var(--mg-warning); }
        .mg-icon-btn.mg-restart:hover:not(:disabled) { background: rgba(202, 138, 4, 0.08); }
        .mg-icon-btn.mg-console { color: #52525b; }
        .mg-icon-btn.mg-console:hover:not(:disabled) { background: var(--mg-subtle); color: var(--mg-text); }
        .mg-icon-btn.mg-rebuild {
            background: var(--mg-danger);
            border: none;
            border-radius: 8px;
            color: #ffffff;
            font-size: 13px;
            font-weight: 500;
            gap: 6px;
            height: 38px;
            padding: 0 14px;
            width: auto;
        }
        .mg-icon-btn.mg-rebuild:hover:not(:disabled) { background: var(--mg-danger-hover); }

        .midgard-ca-banner { border-radius: 8px; display: none; font-size: 13px; margin-top: 14px; padding: 10px 12px; }
        .midgard-ca-banner.mg-error { background: rgba(220, 38, 38, 0.06); border: 1px solid rgba(220, 38, 38, 0.25); color: var(--mg-danger); }
        .midgard-ca-banner.mg-info { background: rgba(99, 102, 241, 0.06); border: 1px solid rgba(99, 102, 241, 0.3); color: var(--mg-indigo-soft); }
        .midgard-ca-banner.mg-show { display: block; }

        /* ── Server Overview header ─────────────────────────────────── */
        .midgard-ca-overview { align-items: center; border-bottom: 1px solid var(--mg-border); display: flex; gap: 12px; justify-content: space-between; margin-bottom: 20px; padding-bottom: 14px; }
        .midgard-ca-overview-title { color: var(--mg-text); font-size: 20px; font-weight: 600; letter-spacing: -0.01em; }
        /* ── Definition rows (2 kolom: kiri identitas, kanan resource) ── */
        .midgard-ca-rows { display: grid; column-gap: 32px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top: 30px; row-gap: 0; }
        .midgard-ca-row { align-items: baseline; display: flex; gap: 12px; padding: 7px 0; }
        .mg-cred-btns { display: inline-flex; gap: 4px; margin-left: auto; }
        .mg-pw-eye .mg-eye-off { display: none; }
        .mg-pw-eye.mg-revealed .mg-eye-on { display: none; }
        .mg-pw-eye.mg-revealed .mg-eye-off { display: block; }
        .mg-toast { background: #18181b; border: 1px solid var(--mg-border-strong); border-radius: 8px; bottom: 24px; box-shadow: 0 8px 24px rgba(0,0,0,0.25); color: #fafafa; font-size: 13px; left: 50%; opacity: 0; padding: 10px 16px; pointer-events: none; position: fixed; transform: translateX(-50%); transition: opacity 0.2s ease; z-index: 10001; }
        .mg-toast.mg-toast-show { opacity: 1; }
        .midgard-ca-label { color: var(--mg-text); flex: 0 0 88px; font-size: 12.5px; font-weight: 700; letter-spacing: 0.02em; }
        .midgard-ca-value { color: var(--mg-text); flex: 1; font-size: 13.5px; font-weight: 600; font-variant-numeric: tabular-nums; min-width: 0; word-break: break-word; }

        /* ── Alerts (provisioning) ──────────────────────────────────── */
        .midgard-clientarea .midgard-ca-alert { border-radius: 8px; font-size: 13px; margin-top: 14px; padding: 10px 12px; }
        .midgard-ca-alert.mg-warn { background: rgba(202, 138, 4, 0.07); border: 1px solid rgba(202, 138, 4, 0.3); color: #854d0e; }
        .midgard-ca-alert.mg-danger { background: rgba(220, 38, 38, 0.06); border: 1px solid rgba(220, 38, 38, 0.25); color: var(--mg-danger); }

        /* ── Rebuild modal ──────────────────────────────────────────── */
        .mg-modal-backdrop {
            align-items: flex-start;
            background: rgba(24, 24, 27, 0.45);
            display: none;
            inset: 0;
            justify-content: center;
            overflow-y: auto;
            padding: 48px 16px;
            position: fixed;
            z-index: 99999;
        }
        .mg-modal-backdrop.mg-open { display: flex; }
        .mg-modal {
            background: #ffffff;
            border: 1px solid var(--mg-border);
            border-radius: 14px;
            box-shadow: 0 20px 50px rgba(24, 24, 27, 0.18);
            color: var(--mg-text);
            max-height: calc(100vh - 96px);
            overflow-y: auto;
            width: min(540px, 100%);
        }
        .mg-modal-head { align-items: center; border-bottom: 1px solid var(--mg-border); display: flex; justify-content: space-between; padding: 15px 18px; }
        .mg-modal-title { font-size: 15px; font-weight: 600; letter-spacing: -0.01em; margin: 0; }
        .mg-modal-x { background: transparent; border: none; color: var(--mg-muted); cursor: pointer; font-size: 18px; line-height: 1; padding: 4px; }
        .mg-modal-x:hover { color: var(--mg-text); }
        .mg-modal-body { padding: 16px 18px 18px; }
        .mg-modal-warn { background: rgba(202, 138, 4, 0.07); border: 1px solid rgba(202, 138, 4, 0.3); border-radius: 8px; color: #854d0e; font-size: 13px; line-height: 1.5; margin-bottom: 16px; padding: 10px 12px; }
        .mg-field { margin-bottom: 14px; }
        .mg-field label { color: var(--mg-muted); display: block; font-size: 11px; font-weight: 600; letter-spacing: 0.05em; margin-bottom: 6px; text-transform: uppercase; }
        .mg-field select, .mg-field input {
            background: #ffffff;
            border: 1px solid var(--mg-border-strong);
            border-radius: 8px;
            color: var(--mg-text);
            font-size: 14px;
            padding: 9px 12px;
            width: 100%;
        }
        .mg-field select:focus, .mg-field input:focus { border-color: var(--mg-indigo); outline: none; }
        .mg-field .mg-hint { color: var(--mg-faint); font-size: 12px; margin-top: 5px; }
        .mg-opt { color: var(--mg-faint); font-size: 11px; font-weight: 500; letter-spacing: 0; text-transform: none; }
        .mg-rebuild-grid { column-gap: 12px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .mg-modal-foot { border-top: 1px solid var(--mg-border); display: flex; gap: 8px; justify-content: flex-end; margin-top: 18px; padding-top: 14px; }
        .mg-btn { border: 1px solid var(--mg-border-strong); border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 500; padding: 9px 16px; }
        .mg-btn-ghost { background: #ffffff; color: var(--mg-muted); }
        .mg-btn-ghost:hover:not(:disabled) { color: var(--mg-text); }
        .mg-btn-primary { background: #4f46e5; border-color: #4f46e5; color: #ffffff; }
        .mg-btn-primary:hover:not(:disabled) { background: #4338ca; border-color: #4338ca; }
        .mg-btn-danger { background: var(--mg-danger); border-color: var(--mg-danger); color: #ffffff; }
        .mg-btn-danger:hover:not(:disabled) { background: var(--mg-danger-hover); }
        .mg-btn:disabled { cursor: not-allowed; opacity: 0.5; }

        /* Rebuild modal is form-only (round 13): checklist/spinner/progress CSS removed. */

        /* ── Rebuild live progress (swaps with the specs grid) ──────── */
        .mg-rebuild-live { margin-top: 30px; }
        .mg-rebuild-live-msg { color: var(--mg-text); font-size: 14px; font-weight: 600; }
        .mg-rebuild-live-track { background: rgba(99, 102, 241, 0.12); border-radius: 999px; height: 8px; margin-top: 12px; overflow: hidden; }
        .mg-rebuild-live-fill { animation: mg-indeterminate 1.4s ease-in-out infinite; background: linear-gradient(90deg, rgba(99,102,241,0.55), var(--mg-indigo)); border-radius: 999px; height: 100%; width: 40%; }
        @keyframes mg-indeterminate { 0% { margin-left: -40%; } 100% { margin-left: 100%; } }

        @keyframes midgard-pulse-green { 0% { box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.35); } 70% { box-shadow: 0 0 0 7px rgba(22, 163, 74, 0); } 100% { box-shadow: 0 0 0 0 rgba(22, 163, 74, 0); } }
        @keyframes midgard-pulse-yellow { 0% { box-shadow: 0 0 0 0 rgba(202, 138, 4, 0.35); } 70% { box-shadow: 0 0 0 7px rgba(202, 138, 4, 0); } 100% { box-shadow: 0 0 0 0 rgba(202, 138, 4, 0); } }
        @keyframes midgard-pulse-red { 0% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.35); } 70% { box-shadow: 0 0 0 7px rgba(220, 38, 38, 0); } 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); } }
        @keyframes midgard-pulse-purple { 0% { box-shadow: 0 0 0 0 rgba(124, 58, 237, 0.35); } 70% { box-shadow: 0 0 0 7px rgba(124, 58, 237, 0); } 100% { box-shadow: 0 0 0 0 rgba(124, 58, 237, 0); } }
        @keyframes midgard-pulse-gray { 0% { box-shadow: 0 0 0 0 rgba(161, 161, 170, 0.35); } 70% { box-shadow: 0 0 0 7px rgba(161, 161, 170, 0); } 100% { box-shadow: 0 0 0 0 rgba(161, 161, 170, 0); } }

        @media (max-width: 767px) {
            .midgard-clientarea.midgard-ca { padding: 16px; }
            .midgard-ca-title { font-size: 16px; }
            .midgard-ca-label { flex-basis: 80px; }
            .midgard-ca-rows { column-gap: 20px; grid-template-columns: 1fr; }
        }
    </style>

    {* ── Server Overview eyebrow: title kiri + live status kanan ─────── *}
    <div class="midgard-ca-overview">
        <span class="midgard-ca-overview-title">Server Overview</span>
        <span class="midgard-header-status midgard-status-state-{$midgardRuntimeStatusClass|default:'default'|escape}">
            <span class="midgard-status-dot" aria-hidden="true"></span>
            <span class="midgard-status-text">{$midgardRuntimeStatusLabel|default:'Unknown'|escape}</span>
        </span>
    </div>

    {* ── Header: name + action bar ───────────────────────────────────── *}
    <div class="midgard-ca-header">
        <h3 class="midgard-ca-title" id="mg-server-title">{$midgardServerName|default:'-'|escape}{if $midgardActionsEnabled}<button type="button" class="mg-icon-btn mg-rename" id="mg-btn-rename" title="Rename server" aria-label="Rename server"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></button>{/if}</h3>

        {if $midgardActionsEnabled}
            <div class="midgard-ca-actions" id="mg-actions">
                <button type="button" class="mg-icon-btn mg-start" id="mg-btn-start" title="Start" aria-label="Start server">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3" fill="currentColor" stroke="none"/></svg>
                </button>
                <button type="button" class="mg-icon-btn mg-stop" id="mg-btn-stop" title="Stop" aria-label="Stop server">
                    <svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><rect x="6" y="6" width="12" height="12" rx="1"/></svg>
                </button>
                <button type="button" class="mg-icon-btn mg-restart" id="mg-btn-restart" title="Restart" aria-label="Restart server">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><polyline points="21 3 21 9 15 9"/></svg>
                </button>
                <span class="mg-sep" aria-hidden="true"></span>
                <button type="button" class="mg-icon-btn mg-rebuild" id="mg-btn-rebuild" title="Rebuild">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
                    <span>Rebuild</span>
                </button>
                <span class="mg-sep" aria-hidden="true"></span>
                <button type="button" class="mg-icon-btn mg-console" id="mg-btn-console" title="Console" aria-label="Open console">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="4" width="19" height="13" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/></svg>
                </button>
            </div>
        {/if}
    </div>

    <div class="midgard-ca-banner" id="mg-banner" role="status"></div>

    {* ── Definition rows ─────────────────────────────────────────────── *
     * Grid 2 kolom, auto-placement mengisi baris-per-baris. Urutan DOM
     * di-buat sehingga hasil visualnya:
     *   kolom kiri: VMID, Hostname, CPU, RAM, Disk
     *   kolom kanan: OS, Location, Bandwidth, IPv4, IPv6
     *}
    <div class="midgard-ca-rows" id="mg-specs">
        <div class="midgard-ca-row">
            <span class="midgard-ca-label">VMID</span>
            <span class="midgard-ca-value">{$midgardVmid|default:'-'|escape}</span>
        </div>
        <div class="midgard-ca-row">
            <span class="midgard-ca-label">Location</span>
            <span class="midgard-ca-value">{$midgardLocation|default:'-'|escape}</span>
        </div>
        <div class="midgard-ca-row">
            <span class="midgard-ca-label">Hostname</span>
            <span class="midgard-ca-value" id="mg-hostname-value">{$midgardServiceHostname|default:$domain|default:'-'|escape}</span>
        </div>
        <div class="midgard-ca-row">
            <span class="midgard-ca-label">Bandwidth</span>
            <span class="midgard-ca-value">{$midgardSpecs.bandwidth_tb|default:'-'} TB</span>
        </div>
        <div class="midgard-ca-row">
            <span class="midgard-ca-label">CPU</span>
            <span class="midgard-ca-value">{$midgardSpecs.cpu|default:'-'} Core(s)</span>
        </div>
        <div class="midgard-ca-row">
            <span class="midgard-ca-label">IPv4</span>
            <span class="midgard-ca-value" id="mg-ipv4-value">{$midgardPrimaryIpv4|default:'-'|escape}</span>
        </div>
        <div class="midgard-ca-row">
            <span class="midgard-ca-label">RAM</span>
            <span class="midgard-ca-value">{$midgardSpecs.memory_gb|default:'-'} GB</span>
        </div>
        <div class="midgard-ca-row">
            <span class="midgard-ca-label">IPv6</span>
            <span class="midgard-ca-value" id="mg-ipv6-value">{$midgardPrimaryIpv6|default:'-'|escape}</span>
        </div>
        <div class="midgard-ca-row">
            <span class="midgard-ca-label">Disk</span>
            <span class="midgard-ca-value">{$midgardSpecs.disk_gb|default:'-'} GB</span>
        </div>
        <div class="midgard-ca-row">
            <span class="midgard-ca-label">User</span>
            <span class="midgard-ca-value" id="mg-user-value">{$midgardOsUser|default:'root'|escape}</span>
        </div>
        <div class="midgard-ca-row">
            <span class="midgard-ca-label">OS</span>
            <span class="midgard-ca-value" id="mg-card-os-value">{$midgardOsName|default:'-'|escape}</span>
        </div>
        <div class="midgard-ca-row">
            <span class="midgard-ca-label">Password</span>
            <span class="midgard-ca-value" id="mg-password-value" data-masked="1">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</span>
            {if $midgardActionsEnabled}
            <span class="mg-cred-btns">
                <button type="button" class="mg-icon-btn mg-pw-eye" id="mg-pw-eye" title="Show password" aria-label="Show password" aria-pressed="false">
                    <svg class="mg-eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg class="mg-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
                <button type="button" class="mg-icon-btn mg-pw-copy" id="mg-pw-copy" title="Copy password" aria-label="Copy password">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                </button>
            </span>
            {/if}
        </div>
    </div>

    {* ── Rebuild progress (swaps in for the specs grid while rebuilding) *}
    <div class="mg-rebuild-live" id="mg-rebuild-live" style="display: none;">
        <div class="mg-rebuild-live-msg" id="mg-rebuild-live-msg">Rebuilding your server. This can take several minutes.</div>
        <div class="mg-rebuild-live-track"><div class="mg-rebuild-live-fill"></div></div>
    </div>

    {if $midgardIpv4Missing}
        <div class="midgard-ca-alert mg-warn">{$midgardIpv4Warning|escape}</div>
    {/if}

    {if $midgardProvisionError}
        <div class="midgard-ca-alert mg-danger">{$midgardProvisionError|escape}</div>
    {/if}

    {* ── Rebuild modal ───────────────────────────────────────────────── *}
    <div class="mg-modal-backdrop" id="mg-rebuild-backdrop">
        <div class="mg-modal" role="dialog" aria-modal="true" aria-label="Rebuild server">
            <div class="mg-modal-head">
                <h4 class="mg-modal-title">Rebuild server</h4>
                <button type="button" class="mg-modal-x" id="mg-rebuild-x" aria-label="Close">&times;</button>
            </div>
            <div class="mg-modal-body">
                <div class="mg-modal-warn">Rebuilding will permanently erase all data on this server. The server will be reinstalled with the selected operating system.</div>

                <div id="mg-rebuild-phase-form">
                    <div class="mg-field">
                        <label for="mg-rebuild-template">Template</label>
                        <select id="mg-rebuild-template"><option>Loading templates&hellip;</option></select>
                        <div class="mg-hint" id="mg-rebuild-template-hint"></div>
                    </div>
                    <div class="mg-rebuild-grid">
                        <div class="mg-field">
                            <label for="mg-rebuild-name">Server Name</label>
                            <input type="text" id="mg-rebuild-name" autocomplete="off" value="{$midgardServerName|default:''|escape}" placeholder="Midgard Server" />
                        </div>
                        <div class="mg-field">
                            <label for="mg-rebuild-hostname">Hostname <span class="mg-opt">(Optional)</span></label>
                            <input type="text" id="mg-rebuild-hostname" autocomplete="off" value="{$midgardServiceHostname|default:''|escape}" placeholder="server.example.com" />
                        </div>
                    </div>
                    <div class="mg-field">
                        <label for="mg-rebuild-password">New OS Password <span class="mg-opt">(Optional)</span></label>
                        <input type="password" id="mg-rebuild-password" autocomplete="new-password" placeholder="Leave blank to keep existing" />
                    </div>
                    <div class="mg-modal-foot">
                        <button type="button" class="mg-btn mg-btn-ghost" id="mg-rebuild-cancel">Cancel</button>
                        <button type="button" class="mg-btn mg-btn-danger" id="mg-rebuild-confirm" disabled>Rebuild</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {* ── Rename modal (identity edit; hostnames inside the OS only change on rebuild) *}
    <div class="mg-modal-backdrop" id="mg-rename-backdrop" aria-hidden="true">
        <div class="mg-modal" role="dialog" aria-modal="true" aria-labelledby="mg-rename-title">
            <div class="mg-modal-head">
                <h4 class="mg-modal-title" id="mg-rename-title">Rename server</h4>
                <button type="button" class="mg-modal-x" id="mg-rename-x" aria-label="Close">&times;</button>
            </div>
            <div class="mg-modal-body">
                <div class="mg-rebuild-grid">
                    <div class="mg-field">
                        <label for="mg-rename-name">Server Name</label>
                        <input type="text" id="mg-rename-name" autocomplete="off" value="{$midgardServerName|default:''|escape}" placeholder="Midgard Server" />
                    </div>
                    <div class="mg-field">
                        <label for="mg-rename-hostname">Hostname <span class="mg-opt">(Optional)</span></label>
                        <input type="text" id="mg-rename-hostname" autocomplete="off" value="{$midgardServiceHostname|default:''|escape}" placeholder="server.example.com" />
                        <div class="mg-hint">The hostname inside the operating system only changes on rebuild.</div>
                    </div>
                </div>
                <div class="mg-modal-foot">
                    <button type="button" class="mg-btn mg-btn-ghost" id="mg-rename-cancel">Cancel</button>
                    <button type="button" class="mg-btn mg-btn-primary" id="mg-rename-save" disabled>Save</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var root = document.getElementById('midgard-ca');
            if (!root) { return; }

            var cfg = {
                actionsEnabled: root.getAttribute('data-actions-enabled') === '1',
                ajaxUrl: root.getAttribute('data-ajax-url'),
                csrf: root.getAttribute('data-csrf'),
                serviceId: root.getAttribute('data-service-id')
            };

            var STATUS_LABELS = { running: 'RUNNING', stopped: 'STOPPED', suspended: 'SUSPENDED', installing: 'INSTALLING', rebuilding: 'REBUILDING', restoring: 'RESTORING', creating: 'CREATING', deleting: 'DELETING' };
            var STATUS_CLASSES = { running: 'success', stopped: 'default', suspended: 'suspended', installing: 'warning', rebuilding: 'warning', restoring: 'warning', creating: 'warning', deleting: 'danger' };
            var TRANSITIONAL = ['installing', 'rebuilding', 'restoring', 'creating', 'deleting'];

            function $(id) { return document.getElementById(id); }

            function showBanner(kind, text) {
                var b = $('mg-banner');
                if (!b) { return; }
                b.className = 'midgard-ca-banner mg-show ' + (kind === 'error' ? 'mg-error' : 'mg-info');
                b.textContent = text;
            }

            function hideBanner() {
                var b = $('mg-banner');
                if (b) { b.className = 'midgard-ca-banner'; }
            }

            function api(action, payload) {
                var body = new URLSearchParams();
                body.append('action', action);
                if (payload) {
                    for (var k in payload) {
                        if (Object.prototype.hasOwnProperty.call(payload, k)) {
                            body.append(k, payload[k]);
                        }
                    }
                }
                return fetch(cfg.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-Midgard-CSRF': cfg.csrf
                    },
                    body: body.toString()
                }).then(function (res) {
                    return res.json().catch(function () { throw new Error('Unexpected server response'); });
                }).then(function (data) {
                    if (!data || data.status !== 'ok') {
                        throw new Error((data && data.message) ? data.message : 'Request failed');
                    }
                    return data.data || {};
                });
            }

            /* ── Status-aware action bar ─────────────────────────────── */
            // Seed from the server-rendered status so the bar is correct
            // on first paint (no flash of a Start button while running).
            var currentStatus = root.getAttribute('data-status') || null;
            var busy = false;

            function setButtons() {
                if (!cfg.actionsEnabled) { return; }
                var start = $('mg-btn-start'), stop = $('mg-btn-stop'), restart = $('mg-btn-restart'), rebuild = $('mg-btn-rebuild'), console_ = $('mg-btn-console');
                if (!start) { return; }
                var transitional = TRANSITIONAL.indexOf(currentStatus) !== -1;
                var locked = busy || transitional || currentStatus === 'suspended';
                // Panel parity: while RUNNING the Start button disappears
                // entirely — the bar shows only Stop / Restart / Rebuild /
                // Console. It comes back the moment the server is stopped.
                start.style.display = currentStatus === 'running' ? 'none' : '';
                start.disabled = locked || currentStatus !== 'stopped';
                stop.disabled = locked || currentStatus !== 'running';
                restart.disabled = locked || currentStatus !== 'running';
                rebuild.disabled = busy || transitional || currentStatus === 'suspended';
                console_.disabled = busy || currentStatus === 'stopped' || currentStatus === 'suspended';
            }

            function setStatus(status) {
                currentStatus = status;
                if (TRANSITIONAL.indexOf(status) === -1) { rebuildFlow = false; }
                syncRebuildLive();
                var wrap = root.querySelector('.midgard-header-status');
                if (wrap && status) {
                    var cls = STATUS_CLASSES[status] || 'default';
                    wrap.className = 'midgard-header-status midgard-status-state-' + cls;
                    var txt = wrap.querySelector('.midgard-status-text');
                    if (txt) { txt.textContent = STATUS_LABELS[status] || status.toUpperCase(); }
                }
                setButtons();
            }

            function act(action) {
                if (busy) { return; }
                busy = true;
                hideBanner();
                setButtons();
                api('power', { action_param: action }).then(function (result) {
                    showBanner('info', result.message || 'Command sent.');
                }).catch(function (err) {
                    showBanner('error', err.message);
                }).finally(function () {
                    busy = false;
                    startStatusWatcher(2);
                });
            }

            function refreshStatus() {
                return api('status').then(function (data) {
                    if (data.status && data.status !== 'unknown') { setStatus(data.status); }
                    if (data.os_name) {
                        var os = $('mg-card-os-value');
                        if (os) { os.textContent = data.os_name; }
                    }
                    // Live identity: a rebuild that renamed the server must
                    // land on the page without a manual reload.
                    if (data.name) {
                        var title = $('mg-server-title');
                        if (title && title.textContent !== data.name) { title.textContent = data.name; }
                    }
                    // User row follows the OS distro (rebuild linux↔windows flips root↔Administrator live).
                    if (data.os_user) {
                        var userCell = $('mg-user-value');
                        if (userCell && userCell.textContent !== data.os_user) { userCell.textContent = data.os_user; }
                    }
                    // IP rows live-sync from synced meta (rebuilds keep the
                    // same addresses, but early-create refreshes converge the
                    // display as soon as meta hydrates).
                    if (data.ipv4 !== undefined) {
                        var v4 = $('mg-ipv4-value');
                        if (v4 && v4.textContent !== data.ipv4) { v4.textContent = data.ipv4 || '-'; }
                    }
                    if (data.ipv6 !== undefined) {
                        var v6 = $('mg-ipv6-value');
                        if (v6 && v6.textContent !== data.ipv6) { v6.textContent = data.ipv6 || '-'; }
                    }
                    if (data.hostname) {
                        var hn = $('mg-hostname-value');
                        if (hn && hn.textContent !== data.hostname) { hn.textContent = data.hostname; }
                        // Keep the rebuild form's pre-fill fresh too — but
                        // never clobber fields while the modal is open
                        // (the user may be mid-edit).
                        if (!modalOpen) {
                            var mh = $('mg-rebuild-hostname');
                            if (mh && mh.value !== data.hostname) { mh.value = data.hostname; }
                        }
                    }
                    if (data.name && !modalOpen) {
                        var mn = $('mg-rebuild-name');
                        if (mn && mn.value !== data.name) { mn.value = data.name; }
                    }
                    setButtons();
                }).catch(function () {
                    setButtons();
                });
            }

            /* ── Rebuild modal ───────────────────────────────────────── */
            var modalOpen = false;
            var templates = [];

            function openModal() {
                modalOpen = true;
                $('mg-rebuild-backdrop').className = 'mg-modal-backdrop mg-open';
                showPhase('form');
                hideBanner();
                $('mg-rebuild-password').value = '';
                loadTemplates();
            }

            function closeModal(force) {
                if (rebuilding && !force) { return; }
                modalOpen = false;
                rebuilding = false;
                $('mg-rebuild-backdrop').className = 'mg-modal-backdrop';
            }
            function showPhase(name) {
                // The modal is form-only (round 13): feedback lives in the
                // status badge via the watcher, not in modal phases.
                if (name !== 'form') { return; }
                $('mg-rebuild-phase-form').style.display = '';
            }

            function loadTemplates() {
                var select = $('mg-rebuild-template');
                select.innerHTML = '<option>Loading templates...</option>';
                select.disabled = true;
                $('mg-rebuild-confirm').disabled = true;
                api('osimages').then(function (data) {
                    templates = (data && data.length) ? data : [];
                    if (!templates.length) {
                        select.innerHTML = '<option value="">No compatible templates</option>';
                        return;
                    }
                    select.innerHTML = '';
                    for (var i = 0; i < templates.length; i++) {
                        var opt = document.createElement('option');
                        opt.value = templates[i].id;
                        opt.textContent = templates[i].name;
                        select.appendChild(opt);
                    }
                    select.disabled = false;
                    $('mg-rebuild-confirm').disabled = false;
                }).catch(function (err) {
                    select.innerHTML = '<option value="">Failed to load templates</option>';
                    showBanner('error', err.message);
                });
            }

            var rebuilding = false;

            // ── Live status watcher ─────────────────────────────────────
            // The rebuild flow has NO progress modal: after the panel
            // accepts the rebuild the badge shows REBUILDING and this
            // watcher follows the PANEL's live status until the server is
            // settled again. It survives the modal (badge is the feedback).
            var watchTimer = null;

            function startStatusWatcher(minCycles) {
                stopStatusWatcher();
                var remaining = Math.max(Number(minCycles) || 0, 0);
                watchTimer = setInterval(function () {
                    refreshStatus();
                    remaining -= 1;
                    if (remaining <= 0 && TRANSITIONAL.indexOf(currentStatus) === -1) {
                        stopStatusWatcher();
                    }
                }, 5000);
            }

            function stopStatusWatcher() {
                if (watchTimer) { clearInterval(watchTimer); watchTimer = null; }
            }

            // While rebuilding, the specs grid swaps for a live progress
            // bar; both return the moment the status settles. `installing`
            // only counts inside an active rebuild flow (the panel flips
            // rebuilding→installing mid-task); a bare `rebuilding` (e.g.
            // right after page load) is enough on its own.
            var rebuildFlow = false;

            function showRebuildLive(show) {
                var live = $('mg-rebuild-live');
                var specs = $('mg-specs');
                if (live) { live.style.display = show ? '' : 'none'; }
                if (specs) { specs.style.display = show ? 'none' : ''; }
            }

            function syncRebuildLive() {
                var show = currentStatus === 'rebuilding'
                    || (rebuildFlow && currentStatus === 'installing');
                showRebuildLive(show);
            }

            function startRebuild() {
                var templateId = $('mg-rebuild-template').value;
                if (!templateId || rebuilding) { return; }
                rebuilding = true;
                api('rebuild', {
                    os_image_id: templateId,
                    password: $('mg-rebuild-password').value,
                    name: $('mg-rebuild-name') ? $('mg-rebuild-name').value : '',
                    hostname: $('mg-rebuild-hostname') ? $('mg-rebuild-hostname').value : ''
                }).then(function () {
                    rebuilding = false;
                    // Optimistically REBUILDING immediately — the watcher
                    // then follows the panel until settled (min 30 s so the
                    // brief "still running" gap right after acceptance
                    // can't stop it prematurely).
                    rebuildFlow = true;
                    setStatus('rebuilding');
                    startStatusWatcher(6);
                    closeModal(true);
                }).catch(function (err) {
                    rebuilding = false;
                    closeModal(true);
                    showBanner('error', err.message || 'Rebuild failed to start.');
                });
            }

            /* ── Console (served by the PANEL via SSO) ────────────────── */
            function openConsole() {
                if (busy) { return; }
                busy = true;
                hideBanner();
                setButtons();
                api('sso').then(function (result) {
                    var url = (result && result.url) || '';
                    if (!url) {
                        showBanner('error', 'Panel did not return a console address.');
                        return;
                    }
                    var win = window.open(url, 'midgard-panel-console', 'width=1280,height=860,menubar=no,toolbar=no');
                    if (win) {
                        try { win.opener = null; } catch (ignored) {}
                        return;
                    }
                    // Popup blocked: leave a manual link instead of silence.
                    var b = $('mg-banner');
                    if (b) {
                        b.className = 'midgard-ca-banner mg-show mg-info';
                        b.textContent = 'Popup diblokir browser — ';
                        var a = document.createElement('a');
                        a.href = url;
                        a.target = '_blank';
                        a.rel = 'noopener noreferrer';
                        a.textContent = 'klik di sini untuk buka console di panel';
                        a.style.color = '#4f46e5';
                        a.style.textDecoration = 'underline';
                        b.appendChild(a);
                    }
                }).catch(function (err) {
                    showBanner('error', err.message || 'Could not open the console.');
                }).finally(function () {
                    busy = false;
                    setButtons();
                });
            }

            /* ── Wire up ─────────────────────────────────────────────── */
            if (cfg.actionsEnabled) {
                setButtons();
                $('mg-btn-start').addEventListener('click', function () { act('start'); });
                $('mg-btn-stop').addEventListener('click', function () { act('stop'); });
                $('mg-btn-restart').addEventListener('click', function () { act('restart'); });
                $('mg-btn-rebuild').addEventListener('click', openModal);
                $('mg-btn-console').addEventListener('click', openConsole);

                $('mg-rebuild-x').addEventListener('click', closeModal);
                $('mg-rebuild-cancel').addEventListener('click', closeModal);
                $('mg-rebuild-confirm').addEventListener('click', startRebuild);
                $('mg-rebuild-backdrop').addEventListener('click', function (e) {
                    if (e.target === this) { closeModal(); }
                });

                // A page (re)loaded mid-rebuild must keep following the
                // panel too — not only the tab that clicked Rebuild.
                refreshStatus().then(function () {
                    if (TRANSITIONAL.indexOf(currentStatus) !== -1) { startStatusWatcher(); }
                });
            }

            /* ── Hide WHMCS-native rows (hostname / primary ip) ─────── */
            function stripNativeRows(container) {
                if (!container) { return; }
                var current = container;
                var maxDepth = 6;
                while (current && current.parentElement && maxDepth > 0) {
                    var host = current.parentElement;
                    var siblings = Array.prototype.slice.call(host.children || []);
                    for (var i = 0; i < siblings.length; i++) {
                        var node = siblings[i];
                        if (node === current) { break; }
                        if (!node || node.nodeType !== 1) { continue; }
                        var text = (node.textContent || '').toLowerCase();
                        if (text.indexOf('hostname') !== -1 || text.indexOf('primary ip') !== -1) {
                            if (node.parentNode) { node.parentNode.removeChild(node); }
                        }
                    }
                    current = host;
                    maxDepth--;
                }
            }

            function run() { stripNativeRows(root); }

            /* ── Toast helper ──────────────────────────────────────────── */
            var toastEl = null;
            var toastTimer = null;
            function toast(message) {
                if (!toastEl) {
                    toastEl = document.createElement('div');
                    toastEl.className = 'mg-toast';
                    document.body.appendChild(toastEl);
                }
                toastEl.textContent = message;
                toastEl.className = 'mg-toast mg-toast-show';
                if (toastTimer) { clearTimeout(toastTimer); }
                toastTimer = setTimeout(function () {
                    toastEl.className = 'mg-toast';
                }, 4000);
            }

            /* ── Password reveal + copy (on-demand fetch, never in HTML) ── */
            var pwRevealed = false;
            var pwValue = '';
            var eyeBtn = $('mg-pw-eye');
            var copyBtn = $('mg-pw-copy');
            var pwCell = $('mg-password-value');

            function pwShow() {
                pwCell.textContent = pwValue;
                pwCell.removeAttribute('data-masked');
                eyeBtn.className = 'mg-icon-btn mg-pw-eye mg-revealed';
                eyeBtn.setAttribute('aria-pressed', 'true');
                eyeBtn.title = 'Hide password';
            }
            function pwHide() {
                pwCell.textContent = '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022';
                pwCell.setAttribute('data-masked', '1');
                eyeBtn.className = 'mg-icon-btn mg-pw-eye';
                eyeBtn.setAttribute('aria-pressed', 'false');
                eyeBtn.title = 'Show password';
            }

            if (eyeBtn) {
                eyeBtn.addEventListener('click', function () {
                    if (pwRevealed) {
                        pwRevealed = false;
                        pwHide();
                        return;
                    }
                    if (pwValue !== '') {
                        pwRevealed = true;
                        pwShow();
                        return;
                    }
                    eyeBtn.disabled = true;
                    api('credentials', {})
                        .then(function (data) {
                            pwValue = String(data.password || '');
                            pwRevealed = true;
                            pwShow();
                        })
                        .catch(function (err) {
                            toast((err && err.message) ? err.message : 'Password is not available.');
                        })
                        .then(function () { eyeBtn.disabled = false; });
                });
            }

            if (copyBtn) {
                copyBtn.addEventListener('click', function () {
                    var write = function (value) {
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(value).then(function () {
                                toast('Password copied to clipboard.');
                            }, function () {
                                toast('Copy failed — select and copy manually.');
                            });
                        } else {
                            toast('Copy is not supported by this browser.');
                        }
                    };
                    if (pwValue !== '') {
                        write(pwValue);
                        return;
                    }
                    copyBtn.disabled = true;
                    api('credentials', {})
                        .then(function (data) {
                            pwValue = String(data.password || '');
                            if (!pwRevealed) {
                                pwRevealed = true;
                                pwShow();
                            }
                            write(pwValue);
                        })
                        .catch(function (err) {
                            toast((err && err.message) ? err.message : 'Password is not available.');
                        })
                        .then(function () { copyBtn.disabled = false; });
                });
            }

            /* ── Rename modal (identity) ──────────────────────────────── */
            var renameOpen = false;
            var renameBusy = false;
            var renameBackdrop = $('mg-rename-backdrop');
            var renameName = $('mg-rename-name');
            var renameHostname = $('mg-rename-hostname');
            var renameSave = $('mg-rename-save');
            var renameInitial = { name: '', hostname: '' };

            function renameDirty() {
                return (renameName.value.trim() !== '' && renameName.value.trim() !== renameInitial.name) ||
                       (renameHostname.value.trim() !== '' && renameHostname.value.trim() !== renameInitial.hostname);
            }
            function renameRefreshSave() {
                renameSave.disabled = renameBusy || !renameDirty();
            }
            function openRename() {
                renameOpen = true;
                renameInitial.name = $('mg-server-title').textContent.trim();
                renameInitial.hostname = ($('mg-hostname-value') ? $('mg-hostname-value').textContent.trim() : '');
                renameName.value = renameInitial.name;
                renameHostname.value = renameInitial.hostname;
                renameRefreshSave();
                renameBackdrop.className = 'mg-modal-backdrop mg-open';
                renameName.focus();
            }
            function closeRename() {
                renameOpen = false;
                renameBackdrop.className = 'mg-modal-backdrop';
            }

            var renameBtn = $('mg-btn-rename');
            if (renameBtn) {
                renameBtn.addEventListener('click', openRename);
            }
            if ($('mg-rename-x')) { $('mg-rename-x').addEventListener('click', closeRename); }
            if ($('mg-rename-cancel')) { $('mg-rename-cancel').addEventListener('click', closeRename); }
            if (renameBackdrop) {
                renameBackdrop.addEventListener('click', function (e) {
                    if (e.target === renameBackdrop) { closeRename(); }
                });
            }
            if (renameName) {
                renameName.addEventListener('input', renameRefreshSave);
                renameHostname.addEventListener('input', renameRefreshSave);
                renameName.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' && !renameSave.disabled) { renameSave.click(); }
                    if (e.key === 'Escape') { closeRename(); }
                });
            }

            if (renameSave) {
                renameSave.addEventListener('click', function () {
                    if (renameBusy) { return; }
                    var name = renameName.value.trim();
                    var hostname = renameHostname.value.trim();
                    if (name === '' && hostname === '') { return; }

                    var payload = {};
                    if (name !== '') { payload.name = name; }
                    if (hostname !== '') { payload.hostname = hostname; }

                    var prevHostname = renameInitial.hostname;
                    renameBusy = true;
                    renameSave.disabled = true;
                    renameSave.textContent = 'Saving…';
                    api('identity', payload)
                        .then(function (data) {
                            if (data.name) {
                                $('mg-server-title').textContent = data.name;
                                renameInitial.name = data.name;
                            }
                            if (data.hostname && $('mg-hostname-value')) {
                                $('mg-hostname-value').textContent = data.hostname;
                                renameInitial.hostname = data.hostname;
                            }
                            closeRename();
                            // Per spec: silent on name-only changes; the reboot
                            // toast fires ONLY when the hostname changed.
                            if (data.hostname && data.hostname !== prevHostname) {
                                toast('Reboot the server for the hostname change to take effect inside the OS.');
                            }
                        })
                        .catch(function (err) {
                            toast((err && err.message) ? err.message : 'Rename failed.');
                        })
                        .then(function () {
                            renameBusy = false;
                            renameSave.textContent = 'Save';
                            renameRefreshSave();
                        });
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function () {
                    run();
                    setTimeout(run, 120);
                    setTimeout(run, 450);
                });
            } else {
                run();
                setTimeout(run, 120);
                setTimeout(run, 450);
            }
        })();
    </script>
</div>
