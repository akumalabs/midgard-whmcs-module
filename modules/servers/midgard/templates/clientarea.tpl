<div class="midgard-clientarea">
    <style>
        .midgard-clientarea .midgard-status-panel .panel-body {
            padding: 14px 16px;
            text-align: center;
        }

        .midgard-clientarea .midgard-status-title {
            font-weight: 600;
            margin-right: 8px;
        }

        .midgard-clientarea .midgard-card .panel-heading {
            text-align: center;
        }

        .midgard-clientarea .midgard-card .panel-title {
            font-size: 20px;
            font-weight: 500;
            line-height: 1.3;
        }

        .midgard-clientarea .midgard-card .panel-body {
            padding: 18px 20px;
        }

        .midgard-clientarea .midgard-kv-list {
            margin: 0;
        }

        .midgard-clientarea .midgard-kv-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 20px;
            padding: 8px 0;
        }

        .midgard-clientarea .midgard-kv-label {
            color: #2f3337;
            font-weight: 600;
            min-width: 140px;
            text-align: left;
        }

        .midgard-clientarea .midgard-kv-value {
            color: #2f3337;
            flex: 1 1 auto;
            text-align: right;
            word-break: break-word;
        }

        .midgard-clientarea .midgard-action-panel .panel-body {
            padding: 12px 16px;
        }

        /* Fallback: hide theme-generated Hostname/Primary IP summary above this module block. */
        :where(.module-client-area, .moduleclientarea, .tab-content, .tab-pane):has(> .midgard-clientarea) > :not(.midgard-clientarea):first-child {
            display: none !important;
        }

        @media (max-width: 767px) {
            .midgard-clientarea .midgard-kv-row {
                align-items: flex-start;
                flex-direction: column;
                gap: 2px;
                padding: 7px 0;
            }

            .midgard-clientarea .midgard-kv-label,
            .midgard-clientarea .midgard-kv-value {
                min-width: 0;
                text-align: left;
            }
        }
    </style>

    <div class="panel panel-default card mb-3 midgard-status-panel">
        <div class="panel-body card-body">
            <span class="midgard-status-title">Server Status:</span>
            <span class="label label-{$midgardRuntimeStatusClass|default:'default'|escape}">{$midgardRuntimeStatusLabel|default:'Unknown'|escape}</span>

            {if $midgardIpv4Missing}
                <div class="alert alert-warning" style="margin-top: 12px; margin-bottom: 0;">{$midgardIpv4Warning|escape}</div>
            {/if}

            {if $midgardProvisionError}
                <div class="alert alert-danger" style="margin-top: 12px; margin-bottom: 0;">{$midgardProvisionError|escape}</div>
            {/if}
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="panel panel-default card mb-3 midgard-card">
                <div class="panel-heading card-header">
                    <h3 class="panel-title card-title m-0">Server Overview</h3>
                </div>
                <div class="panel-body card-body">
                    <div class="midgard-kv-list">
                        <div class="midgard-kv-row">
                            <span class="midgard-kv-label">Server Name</span>
                            <span class="midgard-kv-value">{$midgardServerName|default:'-'|escape}</span>
                        </div>
                        <div class="midgard-kv-row">
                            <span class="midgard-kv-label">Hostname</span>
                            <span class="midgard-kv-value">{$midgardServiceHostname|default:$domain|default:'-'|escape}</span>
                        </div>
                        <div class="midgard-kv-row">
                            <span class="midgard-kv-label">Primary IPv4</span>
                            <span class="midgard-kv-value">{$midgardPrimaryIpv4|default:'-'|escape}</span>
                        </div>
                        <div class="midgard-kv-row">
                            <span class="midgard-kv-label">Primary IPv6</span>
                            <span class="midgard-kv-value">{$midgardPrimaryIpv6|default:'-'|escape}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="panel panel-default card mb-3 midgard-card">
                <div class="panel-heading card-header">
                    <h3 class="panel-title card-title m-0">Server Resources</h3>
                </div>
                <div class="panel-body card-body">
                    <div class="midgard-kv-list">
                        <div class="midgard-kv-row">
                            <span class="midgard-kv-label">CPU</span>
                            <span class="midgard-kv-value">{$midgardSpecs.cpu|default:0|escape}</span>
                        </div>
                        <div class="midgard-kv-row">
                            <span class="midgard-kv-label">RAM</span>
                            <span class="midgard-kv-value">{$midgardSpecs.memory_gb|default:0|escape} GB</span>
                        </div>
                        <div class="midgard-kv-row">
                            <span class="midgard-kv-label">Disk</span>
                            <span class="midgard-kv-value">{$midgardSpecs.disk_gb|default:0|escape} GB</span>
                        </div>
                        <div class="midgard-kv-row">
                            <span class="midgard-kv-label">Bandwidth</span>
                            <span class="midgard-kv-value">{$midgardSpecs.bandwidth_tb|default:0|escape} TB</span>
                        </div>
                        <div class="midgard-kv-row">
                            <span class="midgard-kv-label">Backup Limit</span>
                            <span class="midgard-kv-value">{$midgardSpecs.backup_limit|default:0|escape}</span>
                        </div>
                        <div class="midgard-kv-row">
                            <span class="midgard-kv-label">Snapshot Limit</span>
                            <span class="midgard-kv-value">{$midgardSpecs.snapshot_limit|default:0|escape}</span>
                        </div>
                    </div>
                </div>
            </div>
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
</div>
