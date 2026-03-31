<div class="midgard-clientarea">
    <style>
        .midgard-clientarea .midgard-overview-panel .panel-heading {
            align-items: center;
            display: flex;
            justify-content: space-between;
            padding: 14px 18px;
        }

        .midgard-clientarea .midgard-overview-panel .panel-title {
            color: #2f3337;
            font-size: 30px;
            font-weight: 500;
            line-height: 1.2;
            margin: 0;
            text-align: left;
        }

        .midgard-clientarea .midgard-header-status {
            align-items: center;
            display: inline-flex;
            gap: 8px;
            margin-left: 14px;
        }

        .midgard-clientarea .midgard-status-dot {
            border-radius: 50%;
            display: inline-block;
            height: 9px;
            width: 9px;
        }

        .midgard-clientarea .midgard-status-text {
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .midgard-clientarea .midgard-status-state-success {
            color: #2ea44f;
        }

        .midgard-clientarea .midgard-status-state-success .midgard-status-dot {
            background: #2ea44f;
            box-shadow: 0 0 0 rgba(46, 164, 79, 0.5);
            animation: midgard-pulse-green 1.6s infinite;
        }

        .midgard-clientarea .midgard-status-state-warning {
            color: #d29922;
        }

        .midgard-clientarea .midgard-status-state-warning .midgard-status-dot {
            background: #d29922;
            box-shadow: 0 0 0 rgba(210, 153, 34, 0.45);
            animation: midgard-pulse-yellow 1.6s infinite;
        }

        .midgard-clientarea .midgard-status-state-danger {
            color: #cf222e;
        }

        .midgard-clientarea .midgard-status-state-danger .midgard-status-dot {
            background: #cf222e;
            box-shadow: 0 0 0 rgba(207, 34, 46, 0.45);
            animation: midgard-pulse-red 1.6s infinite;
        }

        .midgard-clientarea .midgard-status-state-info {
            color: #0969da;
        }

        .midgard-clientarea .midgard-status-state-info .midgard-status-dot {
            background: #0969da;
            box-shadow: 0 0 0 rgba(9, 105, 218, 0.45);
            animation: midgard-pulse-blue 1.6s infinite;
        }

        .midgard-clientarea .midgard-status-state-default {
            color: #57606a;
        }

        .midgard-clientarea .midgard-status-state-default .midgard-status-dot {
            background: #57606a;
            box-shadow: 0 0 0 rgba(87, 96, 106, 0.45);
            animation: midgard-pulse-gray 1.6s infinite;
        }

        .midgard-clientarea .midgard-overview-panel .panel-body {
            padding: 22px 24px;
        }

        .midgard-clientarea .midgard-overview-row {
            display: grid;
            gap: 14px 26px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            margin: 0;
        }

        .midgard-clientarea .midgard-overview-row + .midgard-overview-row {
            margin-top: 14px;
        }

        .midgard-clientarea .midgard-overview-spacer {
            height: 14px;
        }

        .midgard-clientarea .midgard-overview-item {
            display: flex;
            align-items: baseline;
            gap: 8px;
            min-width: 0;
        }

        .midgard-clientarea .midgard-overview-label {
            color: #2f3337;
            font-weight: 600;
            min-width: 116px;
            text-align: left;
            white-space: nowrap;
        }

        .midgard-clientarea .midgard-overview-value {
            color: #2f3337;
            flex: 1;
            text-align: left;
            word-break: break-word;
        }

        .midgard-clientarea .midgard-action-panel .panel-body {
            padding: 14px 16px;
        }

        .midgard-clientarea .midgard-action-panel .btn {
            font-size: 16px;
            font-weight: 500;
            line-height: 1.25;
            padding: 10px 13px;
        }

        /* Primary CSS kill: hide pre-content siblings in wrappers that directly host Midgard block. */
        :where(.tab-pane, .tab-content, .module-client-area, .moduleclientarea, .panel-body):has(> .midgard-clientarea) > :not(.midgard-clientarea):not(script):not(style) {
            display: none !important;
        }

        @keyframes midgard-pulse-green {
            0% { box-shadow: 0 0 0 0 rgba(46, 164, 79, 0.5); }
            70% { box-shadow: 0 0 0 8px rgba(46, 164, 79, 0); }
            100% { box-shadow: 0 0 0 0 rgba(46, 164, 79, 0); }
        }

        @keyframes midgard-pulse-yellow {
            0% { box-shadow: 0 0 0 0 rgba(210, 153, 34, 0.45); }
            70% { box-shadow: 0 0 0 8px rgba(210, 153, 34, 0); }
            100% { box-shadow: 0 0 0 0 rgba(210, 153, 34, 0); }
        }

        @keyframes midgard-pulse-red {
            0% { box-shadow: 0 0 0 0 rgba(207, 34, 46, 0.45); }
            70% { box-shadow: 0 0 0 8px rgba(207, 34, 46, 0); }
            100% { box-shadow: 0 0 0 0 rgba(207, 34, 46, 0); }
        }

        @keyframes midgard-pulse-blue {
            0% { box-shadow: 0 0 0 0 rgba(9, 105, 218, 0.45); }
            70% { box-shadow: 0 0 0 8px rgba(9, 105, 218, 0); }
            100% { box-shadow: 0 0 0 0 rgba(9, 105, 218, 0); }
        }

        @keyframes midgard-pulse-gray {
            0% { box-shadow: 0 0 0 0 rgba(87, 96, 106, 0.45); }
            70% { box-shadow: 0 0 0 8px rgba(87, 96, 106, 0); }
            100% { box-shadow: 0 0 0 0 rgba(87, 96, 106, 0); }
        }

        @media (max-width: 767px) {
            .midgard-clientarea .midgard-action-panel .btn {
                font-size: 15px;
            }

            .midgard-clientarea .midgard-overview-panel .panel-title {
                font-size: 24px;
            }

            .midgard-clientarea .midgard-overview-row {
                grid-template-columns: 1fr;
            }

            .midgard-clientarea .midgard-overview-item {
                gap: 6px;
            }

            .midgard-clientarea .midgard-overview-label {
                min-width: 118px;
            }
        }
    </style>

    <div class="panel panel-default card mb-3 midgard-overview-panel">
        <div class="panel-heading card-header">
            <h3 class="panel-title card-title m-0">Server Overview</h3>
            <div class="midgard-header-status midgard-status-state-{$midgardRuntimeStatusClass|default:'default'|escape}">
                <span class="midgard-status-dot" aria-hidden="true"></span>
                <span class="midgard-status-text">{$midgardRuntimeStatusLabel|default:'Unknown'|escape}</span>
            </div>
        </div>
        <div class="panel-body card-body">
            <div class="midgard-overview-row">
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">Name:</span>
                    <span class="midgard-overview-value">{$midgardServerName|default:'-'|escape}</span>
                </div>
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">IPv4:</span>
                    <span class="midgard-overview-value">{$midgardPrimaryIpv4|default:'-'|escape}</span>
                </div>
            </div>

            <div class="midgard-overview-row">
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">Hostname:</span>
                    <span class="midgard-overview-value">{$midgardServiceHostname|default:$domain|default:'-'|escape}</span>
                </div>
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">IPv6:</span>
                    <span class="midgard-overview-value">{$midgardPrimaryIpv6|default:'-'|escape}</span>
                </div>
            </div>

            <div class="midgard-overview-spacer" aria-hidden="true"></div>

            <div class="midgard-overview-row">
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">CPU:</span>
                    <span class="midgard-overview-value">{$midgardSpecs.cpu|default:0|escape}</span>
                </div>
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">Disk:</span>
                    <span class="midgard-overview-value">{$midgardSpecs.disk_gb|default:0|escape} GB</span>
                </div>
            </div>

            <div class="midgard-overview-row">
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">RAM:</span>
                    <span class="midgard-overview-value">{$midgardSpecs.memory_gb|default:0|escape} GB</span>
                </div>
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">Bandwidth:</span>
                    <span class="midgard-overview-value">{$midgardSpecs.bandwidth_tb|default:0|escape} TB</span>
                </div>
            </div>

            <div class="midgard-overview-row">
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">Backup Slot:</span>
                    <span class="midgard-overview-value">{$midgardSpecs.backup_limit|default:0|escape}</span>
                </div>
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">Snapshot Slot:</span>
                    <span class="midgard-overview-value">{$midgardSpecs.snapshot_limit|default:0|escape}</span>
                </div>
            </div>

            {if $midgardIpv4Missing}
                <div class="alert alert-warning" style="margin-top: 14px; margin-bottom: 0;">{$midgardIpv4Warning|escape}</div>
            {/if}

            {if $midgardProvisionError}
                <div class="alert alert-danger" style="margin-top: 14px; margin-bottom: 0;">{$midgardProvisionError|escape}</div>
            {/if}
        </div>
    </div>

    <div class="panel panel-default card mb-3 midgard-action-panel">
        <div class="panel-body card-body">
            {if $midgardSsoUrl}
                <a href="{$midgardSsoUrl|escape}" target="_blank" rel="noopener" class="btn btn-primary btn-block">Open Control Panel</a>
            {else}
                <div class="alert alert-warning" style="margin-bottom: 0;">Control panel SSO is currently unavailable. Please refresh in a moment.</div>
            {/if}
        </div>
    </div>

    <script>
        (function () {
            function stripNativeRows(container) {
                if (!container || !container.parentElement) {
                    return;
                }

                var host = container.parentElement;
                var siblings = Array.prototype.slice.call(host.children || []);

                for (var i = 0; i < siblings.length; i++) {
                    var node = siblings[i];
                    if (node === container) {
                        break;
                    }
                    if (!node || node.nodeType !== 1) {
                        continue;
                    }
                    var text = (node.textContent || '').toLowerCase();
                    if (text.indexOf('hostname') !== -1 || text.indexOf('primary ip') !== -1) {
                        if (node.parentNode) {
                            node.parentNode.removeChild(node);
                        }
                    }
                }
            }

            function run() {
                var container = document.querySelector('.midgard-clientarea');
                stripNativeRows(container);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', run);
            } else {
                run();
            }
        })();
    </script>
</div>
