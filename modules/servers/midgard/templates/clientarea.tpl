<div class="midgard-clientarea">
    <style>
        .midgard-clientarea .midgard-status-panel .panel-body {
            padding: 14px 18px;
            text-align: center;
        }

        .midgard-clientarea .midgard-status-title {
            color: #2f3337;
            font-size: 22px;
            font-weight: 500;
            margin-right: 8px;
            vertical-align: middle;
        }

        .midgard-clientarea .midgard-status-badge {
            display: inline-block;
            font-size: 13px;
            font-weight: 600;
            margin-left: 2px;
            padding: 4px 10px;
            vertical-align: middle;
        }

        .midgard-clientarea .midgard-overview-panel .panel-heading {
            text-align: center;
        }

        .midgard-clientarea .midgard-overview-panel .panel-title {
            font-size: 20px;
            font-weight: 500;
            line-height: 1.3;
        }

        .midgard-clientarea .midgard-overview-panel .panel-body {
            padding: 18px 20px;
        }

        .midgard-clientarea .midgard-overview-grid {
            display: grid;
            gap: 12px 24px;
            margin: 0;
        }

        .midgard-clientarea .midgard-overview-grid + .midgard-overview-grid {
            margin-top: 16px;
        }

        .midgard-clientarea .midgard-overview-grid--two {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .midgard-clientarea .midgard-overview-grid--three {
            grid-template-columns: repeat(3, minmax(0, 1fr));
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
            min-width: 96px;
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
            padding: 12px 16px;
        }

        .midgard-clientarea .midgard-action-panel .btn {
            font-size: 22px;
            font-weight: 400;
            line-height: 1.25;
            padding: 10px 14px;
        }

        /* Fallback: hide theme-generated Hostname/Primary IP strip above this module block. */
        :where(.tab-pane, .tab-content, .module-client-area, .moduleclientarea, .panel-body):has(> .midgard-clientarea) > :first-child:not(.midgard-clientarea),
        :where(.tab-pane, .tab-content, .module-client-area, .moduleclientarea, .panel-body):has(.midgard-clientarea) > :is(.row, .table-container, .table, table, .well, .panel):first-child {
            display: none !important;
        }

        @media (max-width: 767px) {
            .midgard-clientarea .midgard-status-title {
                font-size: 20px;
            }

            .midgard-clientarea .midgard-action-panel .btn {
                font-size: 20px;
            }

            .midgard-clientarea .midgard-overview-grid--two,
            .midgard-clientarea .midgard-overview-grid--three {
                grid-template-columns: 1fr;
            }

            .midgard-clientarea .midgard-overview-item {
                gap: 6px;
            }

            .midgard-clientarea .midgard-overview-label {
                min-width: 104px;
            }
        }
    </style>

    <div class="panel panel-default card mb-3 midgard-status-panel">
        <div class="panel-body card-body">
            <span class="midgard-status-title">Server Status:</span>
            <span class="label midgard-status-badge label-{$midgardRuntimeStatusClass|default:'default'|escape}">{$midgardRuntimeStatusLabel|default:'Unknown'|escape}</span>

            {if $midgardIpv4Missing}
                <div class="alert alert-warning" style="margin-top: 12px; margin-bottom: 0;">{$midgardIpv4Warning|escape}</div>
            {/if}

            {if $midgardProvisionError}
                <div class="alert alert-danger" style="margin-top: 12px; margin-bottom: 0;">{$midgardProvisionError|escape}</div>
            {/if}
        </div>
    </div>

    <div class="panel panel-default card mb-3 midgard-overview-panel">
        <div class="panel-heading card-header">
            <h3 class="panel-title card-title m-0">Server Overview</h3>
        </div>
        <div class="panel-body card-body">
            <div class="midgard-overview-grid midgard-overview-grid--two">
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">Server Name:</span>
                    <span class="midgard-overview-value">{$midgardServerName|default:'-'|escape}</span>
                </div>
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">Primary IPv4:</span>
                    <span class="midgard-overview-value">{$midgardPrimaryIpv4|default:'-'|escape}</span>
                </div>
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">Hostname:</span>
                    <span class="midgard-overview-value">{$midgardServiceHostname|default:$domain|default:'-'|escape}</span>
                </div>
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">Primary IPv6:</span>
                    <span class="midgard-overview-value">{$midgardPrimaryIpv6|default:'-'|escape}</span>
                </div>
            </div>

            <div class="midgard-overview-grid midgard-overview-grid--three">
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">CPU:</span>
                    <span class="midgard-overview-value">{$midgardSpecs.cpu|default:0|escape}</span>
                </div>
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">Disk:</span>
                    <span class="midgard-overview-value">{$midgardSpecs.disk_gb|default:0|escape} GB</span>
                </div>
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">Backup Limit:</span>
                    <span class="midgard-overview-value">{$midgardSpecs.backup_limit|default:0|escape}</span>
                </div>
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">RAM:</span>
                    <span class="midgard-overview-value">{$midgardSpecs.memory_gb|default:0|escape} GB</span>
                </div>
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">Bandwidth:</span>
                    <span class="midgard-overview-value">{$midgardSpecs.bandwidth_tb|default:0|escape} TB</span>
                </div>
                <div class="midgard-overview-item">
                    <span class="midgard-overview-label">Snapshot Limit:</span>
                    <span class="midgard-overview-value">{$midgardSpecs.snapshot_limit|default:0|escape}</span>
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
