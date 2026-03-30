<div class="panel panel-default card mb-3">
    <div class="panel-body card-body" style="padding: 12px 16px;">
        {if $midgardSsoUrl}
            <a href="{$midgardSsoUrl|escape}" target="_blank" rel="noopener" class="btn btn-primary btn-block">Open Control Panel</a>
        {else}
            <div class="alert alert-warning" style="margin-bottom: 0;">Control panel SSO is currently unavailable. Please refresh in a moment.</div>
        {/if}
    </div>
</div>

<div class="panel panel-default card mb-3">
    <div class="panel-body card-body" style="padding: 14px 16px;">
        <strong>Server Status:</strong>
        <span class="label label-{$midgardRuntimeStatusClass|default:'default'|escape}" style="margin-left: 8px;">{$midgardRuntimeStatusLabel|default:'Unknown'|escape}</span>

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
        <div class="panel panel-default card mb-3">
            <div class="panel-heading card-header">
                <h3 class="panel-title card-title m-0">Server Overview</h3>
            </div>
            <div class="panel-body card-body">
                <table class="table table-condensed" style="margin-bottom: 0;">
                    <tr>
                        <th style="width: 45%;">Server Name</th>
                        <td>{$midgardServerName|default:'-'|escape}</td>
                    </tr>
                    <tr>
                        <th>Hostname</th>
                        <td>{$midgardServiceHostname|default:$domain|default:'-'|escape}</td>
                    </tr>
                    <tr>
                        <th>Primary IPv4</th>
                        <td>{$midgardPrimaryIpv4|default:'-'|escape}</td>
                    </tr>
                    <tr>
                        <th>Primary IPv6</th>
                        <td>{$midgardPrimaryIpv6|default:'-'|escape}</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="panel panel-default card mb-3">
            <div class="panel-heading card-header">
                <h3 class="panel-title card-title m-0">Server Resources</h3>
            </div>
            <div class="panel-body card-body">
                <table class="table table-condensed" style="margin-bottom: 0;">
                    <tr>
                        <th style="width: 45%;">CPU</th>
                        <td>{$midgardSpecs.cpu|default:0|escape}</td>
                    </tr>
                    <tr>
                        <th>RAM</th>
                        <td>{$midgardSpecs.memory_gb|default:0|escape} GB</td>
                    </tr>
                    <tr>
                        <th>Disk</th>
                        <td>{$midgardSpecs.disk_gb|default:0|escape} GB</td>
                    </tr>
                    <tr>
                        <th>Bandwidth</th>
                        <td>{$midgardSpecs.bandwidth_tb|default:0|escape} TB</td>
                    </tr>
                    <tr>
                        <th>Backup Limit</th>
                        <td>{$midgardSpecs.backup_limit|default:0|escape}</td>
                    </tr>
                    <tr>
                        <th>Snapshot Limit</th>
                        <td>{$midgardSpecs.snapshot_limit|default:0|escape}</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>
