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
        }

        .midgard-clientarea.midgard-ca *,
        .midgard-clientarea.midgard-ca *::before,
        .midgard-clientarea.midgard-ca *::after { box-sizing: border-box; }

        /* Primary CSS kill: hide pre-content siblings in wrappers that directly host Midgard block. */
        :where(.tab-pane, .tab-content, .module-client-area, .moduleclientarea, .panel-body):has(> .midgard-clientarea) > :not(.midgard-clientarea):not(script):not(style) {
            display: none !important;
        }

        /* ── Header ─────────────────────────────────────────────────── */
        .midgard-ca-header { align-items: center; display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; }
        .midgard-ca-title-wrap { align-items: center; display: flex; gap: 10px; min-width: 0; }
        .midgard-ca-title { color: var(--mg-text); font-size: 18px; font-weight: 600; letter-spacing: -0.01em; line-height: 1.3; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

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

        /* ── Definition rows ────────────────────────────────────────── */
        .midgard-ca-rows { border-top: 1px solid var(--mg-border); display: grid; gap: 0; grid-template-columns: 1fr; margin-top: 20px; padding-top: 6px; }
        .midgard-ca-row { align-items: baseline; border-bottom: 1px solid var(--mg-border); display: flex; gap: 12px; padding: 9px 0; }
        .midgard-ca-label { color: var(--mg-muted); flex: 0 0 88px; font-size: 12px; font-weight: 500; letter-spacing: 0.02em; }
        .midgard-ca-value { color: var(--mg-text); flex: 1; font-size: 13.5px; font-weight: 500; font-variant-numeric: tabular-nums; min-width: 0; word-break: break-word; }

        /* ── Metric cards (single row; wraps to 2x2 on narrow screens) ── */
        .midgard-ca-cards { display: grid; gap: 12px; grid-template-columns: repeat(4, minmax(0, 1fr)); margin-top: 20px; }
        .midgard-ca-card { background: var(--mg-subtle); border: 1px solid var(--mg-border); border-radius: 10px; padding: 14px 16px; }
        .midgard-ca-card .mg-card-label { color: var(--mg-muted); font-size: 11px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; }
        .midgard-ca-card .mg-card-value { color: var(--mg-text); font-size: 20px; font-weight: 600; font-variant-numeric: tabular-nums; letter-spacing: -0.01em; margin-top: 6px; }
        .midgard-ca-card .mg-card-sub { color: var(--mg-faint); font-size: 11.5px; margin-top: 3px; min-height: 15px; }

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
        .mg-modal-foot { border-top: 1px solid var(--mg-border); display: flex; gap: 8px; justify-content: flex-end; margin-top: 18px; padding-top: 14px; }
        .mg-btn { border: 1px solid var(--mg-border-strong); border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 500; padding: 9px 16px; }
        .mg-btn-ghost { background: #ffffff; color: var(--mg-muted); }
        .mg-btn-ghost:hover:not(:disabled) { color: var(--mg-text); }
        .mg-btn-danger { background: var(--mg-danger); border-color: var(--mg-danger); color: #ffffff; }
        .mg-btn-danger:hover:not(:disabled) { background: var(--mg-danger-hover); }
        .mg-btn:disabled { cursor: not-allowed; opacity: 0.5; }

        /* Rebuild checklist */
        .mg-checklist { list-style: none; margin: 0; padding: 0; }
        .mg-check-item { align-items: center; color: var(--mg-faint); display: flex; font-size: 13px; gap: 10px; padding: 7px 0; }
        .mg-check-item.mg-done, .mg-check-item.mg-active { color: var(--mg-text); }
        .mg-check-dot { align-items: center; border: 1px solid var(--mg-border-strong); border-radius: 50%; display: inline-flex; flex: 0 0 18px; height: 18px; justify-content: center; width: 18px; }
        .mg-check-item.mg-done .mg-check-dot { background: rgba(22, 163, 74, 0.1); border-color: var(--mg-success); color: var(--mg-success); }
        .mg-check-item.mg-active .mg-check-dot { border-color: var(--mg-indigo); }
        .mg-spinner { animation: mg-spin 0.8s linear infinite; border: 2px solid rgba(99, 102, 241, 0.25); border-radius: 50%; border-top-color: var(--mg-indigo); display: inline-block; height: 13px; width: 13px; }
        .mg-progress-track { background: var(--mg-subtle); border: 1px solid var(--mg-border); border-radius: 999px; height: 8px; margin-top: 12px; overflow: hidden; }
        .mg-progress-fill { background: var(--mg-indigo); height: 100%; transition: width 0.4s ease; width: 0; }

        @keyframes mg-spin { to { transform: rotate(360deg); } }
        @keyframes midgard-pulse-green { 0% { box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.35); } 70% { box-shadow: 0 0 0 7px rgba(22, 163, 74, 0); } 100% { box-shadow: 0 0 0 0 rgba(22, 163, 74, 0); } }
        @keyframes midgard-pulse-yellow { 0% { box-shadow: 0 0 0 0 rgba(202, 138, 4, 0.35); } 70% { box-shadow: 0 0 0 7px rgba(202, 138, 4, 0); } 100% { box-shadow: 0 0 0 0 rgba(202, 138, 4, 0); } }
        @keyframes midgard-pulse-red { 0% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.35); } 70% { box-shadow: 0 0 0 7px rgba(220, 38, 38, 0); } 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); } }
        @keyframes midgard-pulse-purple { 0% { box-shadow: 0 0 0 0 rgba(124, 58, 237, 0.35); } 70% { box-shadow: 0 0 0 7px rgba(124, 58, 237, 0); } 100% { box-shadow: 0 0 0 0 rgba(124, 58, 237, 0); } }
        @keyframes midgard-pulse-gray { 0% { box-shadow: 0 0 0 0 rgba(161, 161, 170, 0.35); } 70% { box-shadow: 0 0 0 7px rgba(161, 161, 170, 0); } 100% { box-shadow: 0 0 0 0 rgba(161, 161, 170, 0); } }

        @media (max-width: 767px) {
            .midgard-clientarea.midgard-ca { padding: 16px; }
            .midgard-ca-title { font-size: 16px; }
            .midgard-ca-label { flex-basis: 80px; }
            .midgard-ca-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>

    {* ── Header: name + pulse-dot status + action bar ────────────────── *}
    <div class="midgard-ca-header">
        <div class="midgard-ca-title-wrap">
            <h3 class="midgard-ca-title">{$midgardServerName|default:'-'|escape}</h3>
            <span class="midgard-header-status midgard-status-state-{$midgardRuntimeStatusClass|default:'default'|escape}">
                <span class="midgard-status-dot" aria-hidden="true"></span>
                <span class="midgard-status-text">{$midgardRuntimeStatusLabel|default:'Unknown'|escape}</span>
            </span>
        </div>

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
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
                </button>
            </div>
        {/if}
    </div>

    <div class="midgard-ca-banner" id="mg-banner" role="status"></div>

    {* ── Definition rows ─────────────────────────────────────────────── *}
    <div class="midgard-ca-rows">
        <div class="midgard-ca-row">
            <span class="midgard-ca-label">Hostname</span>
            <span class="midgard-ca-value">{$midgardServiceHostname|default:$domain|default:'-'|escape}</span>
        </div>
        <div class="midgard-ca-row">
            <span class="midgard-ca-label">OS</span>
            <span class="midgard-ca-value" id="mg-card-os-value">{$midgardOsName|default:'-'|escape}</span>
        </div>
        <div class="midgard-ca-row">
            <span class="midgard-ca-label">IPv4</span>
            <span class="midgard-ca-value">{$midgardPrimaryIpv4|default:'-'|escape}</span>
        </div>
        <div class="midgard-ca-row">
            <span class="midgard-ca-label">IPv6</span>
            <span class="midgard-ca-value">{$midgardPrimaryIpv6|default:'-'|escape}</span>
        </div>
    </div>

    {* ── Metric cards ────────────────────────────────────────────────── *}
    <div class="midgard-ca-cards">
        <div class="midgard-ca-card">
            <div class="mg-card-label">CPU</div>
            <div class="mg-card-value" id="mg-card-cpu">{$midgardCards.cpu.value|default:'-'|escape}</div>
            <div class="mg-card-sub">{$midgardCards.cpu.sub|default:''|escape}</div>
        </div>
        <div class="midgard-ca-card">
            <div class="mg-card-label">Memory</div>
            <div class="mg-card-value" id="mg-card-memory">{$midgardCards.memory.value|default:'-'|escape}</div>
            <div class="mg-card-sub">{$midgardCards.memory.sub|default:''|escape}</div>
        </div>
        <div class="midgard-ca-card">
            <div class="mg-card-label">Disk</div>
            <div class="mg-card-value" id="mg-card-disk">{$midgardCards.disk.value|default:'-'|escape}</div>
            <div class="mg-card-sub">{$midgardCards.disk.sub|default:''|escape}</div>
        </div>
        <div class="midgard-ca-card">
            <div class="mg-card-label">Bandwidth</div>
            <div class="mg-card-value" id="mg-card-bandwidth">{$midgardCards.bandwidth.value|default:'-'|escape}</div>
            <div class="mg-card-sub">{$midgardCards.bandwidth.sub|default:''|escape}</div>
        </div>
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
                        <label for="mg-rebuild-template">Operating system</label>
                        <select id="mg-rebuild-template"><option>Loading templates&hellip;</option></select>
                        <div class="mg-hint" id="mg-rebuild-template-hint"></div>
                    </div>
                    <div class="mg-field">
                        <label for="mg-rebuild-password">Root password (optional)</label>
                        <input type="text" id="mg-rebuild-password" autocomplete="off" placeholder="Leave empty to auto-generate" />
                        <div class="mg-hint">If left empty, a password is generated and emailed to you.</div>
                    </div>
                    <div class="mg-modal-foot">
                        <button type="button" class="mg-btn mg-btn-ghost" id="mg-rebuild-cancel">Cancel</button>
                        <button type="button" class="mg-btn mg-btn-danger" id="mg-rebuild-confirm" disabled>Rebuild</button>
                    </div>
                </div>

                <div id="mg-rebuild-phase-progress" style="display: none;">
                    <ul class="mg-checklist" id="mg-rebuild-steps"></ul>
                    <div class="mg-progress-track"><div class="mg-progress-fill" id="mg-rebuild-progress"></div></div>
                </div>

                <div id="mg-rebuild-phase-done" style="display: none;">
                    <div class="mg-check-item mg-done"><span class="mg-check-dot">&#10003;</span> Rebuild complete. The new password was emailed to you.</div>
                    <div class="mg-modal-foot">
                        <button type="button" class="mg-btn mg-btn-ghost" id="mg-rebuild-done-close">Close</button>
                    </div>
                </div>

                <div id="mg-rebuild-phase-fail" style="display: none;">
                    <div class="midgard-ca-alert mg-danger" id="mg-rebuild-fail-msg" style="margin-top: 0;">Rebuild failed. Please contact support.</div>
                    <div class="mg-modal-foot">
                        <button type="button" class="mg-btn mg-btn-ghost" id="mg-rebuild-fail-close">Close</button>
                    </div>
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
            var STEPS = ['Stopping server...', 'Deleting server...', 'Installing OS...', 'Configuring resources...', 'Booting server...', 'Finalizing...', 'Complete'];

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
                    refreshStatus();
                });
            }

            function refreshStatus() {
                api('status').then(function (data) {
                    if (data.status && data.status !== 'unknown') { setStatus(data.status); }
                    if (data.os_name) {
                        var os = $('mg-card-os-value');
                        if (os) { os.textContent = data.os_name; }
                    }
                    if (TRANSITIONAL.indexOf(currentStatus) !== -1) {
                        if (!refreshTimer) {
                            refreshTimer = setInterval(function () {
                                if (refreshTimer && TRANSITIONAL.indexOf(currentStatus) === -1) {
                                    clearInterval(refreshTimer);
                                    refreshTimer = null;
                                    return;
                                }
                                api('refresh').then(function (r) {
                                    if (r.status) { setStatus(r.status); }
                                }).catch(function () { /* keep polling */ });
                            }, 15000);
                        }
                    } else if (refreshTimer) {
                        clearInterval(refreshTimer);
                        refreshTimer = null;
                    }
                }).catch(function () {
                    setButtons();
                });
            }

            /* ── Rebuild modal ───────────────────────────────────────── */
            var modalOpen = false;
            var templates = [];
            var pollTimer = null;
            var refreshTimer = null;

            function openModal() {
                modalOpen = true;
                $('mg-rebuild-backdrop').className = 'mg-modal-backdrop mg-open';
                showPhase('form');
                hideBanner();
                $('mg-rebuild-password').value = '';
                loadTemplates();
            }

            function closeModal() {
                if (rebuilding) { return; }
                modalOpen = false;
                $('mg-rebuild-backdrop').className = 'mg-modal-backdrop';
            }

            function showPhase(name) {
                $('mg-rebuild-phase-form').style.display = name === 'form' ? '' : 'none';
                $('mg-rebuild-phase-progress').style.display = name === 'progress' ? '' : 'none';
                $('mg-rebuild-phase-done').style.display = name === 'done' ? '' : 'none';
                $('mg-rebuild-phase-fail').style.display = name === 'fail' ? '' : 'none';
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
            var stepIndex = 0;
            var lastProgress = 0;

            function renderSteps(activeIdx) {
                var ul = $('mg-rebuild-steps');
                ul.innerHTML = '';
                for (var i = 0; i < STEPS.length; i++) {
                    var li = document.createElement('li');
                    var state = i < activeIdx ? 'mg-done' : (i === activeIdx ? 'mg-active' : '');
                    li.className = 'mg-check-item ' + state;
                    var dot = document.createElement('span');
                    dot.className = 'mg-check-dot';
                    if (i < activeIdx) { dot.innerHTML = '&#10003;'; }
                    else if (i === activeIdx) { dot.innerHTML = '<span class="mg-spinner"></span>'; }
                    li.appendChild(dot);
                    li.appendChild(document.createTextNode(STEPS[i]));
                    ul.appendChild(li);
                }
                $('mg-rebuild-progress').style.width = Math.round((activeIdx / (STEPS.length - 1)) * 100) + '%';
            }

            function startRebuild() {
                var templateId = $('mg-rebuild-template').value;
                if (!templateId || rebuilding) { return; }
                rebuilding = true;
                stepIndex = 0;
                lastProgress = 0;
                renderSteps(0);
                showPhase('progress');
                api('rebuild', { os_image_id: templateId, password: $('mg-rebuild-password').value }).then(function () {
                    pollTimer = setInterval(pollRebuild, 1000);
                }).catch(function (err) {
                    rebuilding = false;
                    $('mg-rebuild-fail-msg').textContent = err.message;
                    showPhase('fail');
                });
            }

            function pollRebuild() {
                Promise.all([
                    api('status').catch(function () { return {}; }),
                    api('progress').catch(function () { return {}; })
                ]).then(function (results) {
                    var status = results[0].status || null;
                    var progress = (results[1] && results[1].progress !== undefined && results[1].progress !== null)
                        ? Number(results[1].progress) : null;

                    if (progress !== null && progress !== undefined && progress > lastProgress) {
                        lastProgress = progress;
                        // Installing OS is step index 2
                        if (stepIndex < 2) { stepIndex = 2; }
                    }

                    if (status === 'rebuilding' || status === 'installing') {
                        if (stepIndex < 1) { stepIndex = 1; }
                        renderSteps(stepIndex);
                        return;
                    }

                    if (status === 'running' || status === 'stopped') {
                        if (lastProgress >= 100 && stepIndex >= 4) {
                            stepIndex = STEPS.length - 1;
                            renderSteps(stepIndex);
                            finishRebuild(true);
                        } else if (lastProgress > 0) {
                            renderSteps(Math.max(stepIndex, 3));
                        } else {
                            renderSteps(Math.max(stepIndex, 1));
                        }
                        return;
                    }

                    renderSteps(stepIndex);
                });
            }

            function finishRebuild(ok, message) {
                clearInterval(pollTimer);
                pollTimer = null;
                rebuilding = false;
                if (ok) {
                    showPhase('done');
                } else {
                    $('mg-rebuild-fail-msg').textContent = message || 'Rebuild failed. Please contact support.';
                    showPhase('fail');
                }
                refreshStatus();
            }

            /* ── Console (served by the PANEL via SSO) ────────────────── */
            function openConsole() {
                if (busy) { return; }
                busy = true;
                hideBanner();
                setButtons();
                api('sso').then(function (result) {
                    var url = (result && result.url) || '';
                    if (url) {
                        window.open(url, 'midgard-panel-console', 'width=1280,height=860,menubar=no,toolbar=no');
                    } else {
                        showBanner('error', 'Panel did not return a console address.');
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
                $('mg-rebuild-done-close').addEventListener('click', function () { closeModal(); });
                $('mg-rebuild-fail-close').addEventListener('click', function () { closeModal(); refreshStatus(); });
                $('mg-rebuild-backdrop').addEventListener('click', function (e) {
                    if (e.target === this) { closeModal(); }
                });

                refreshStatus();
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
