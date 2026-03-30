{assign var=midgardCurrentStatus value=$serviceStatus|default:$status}
{assign var=midgardPrimaryIp value=$midgardPrimaryIpv4|default:$midgardPrimaryIpv6|default:'-'}

<div class="panel panel-default card mb-3">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Server Overview</h3>
    </div>
    <div class="panel-body card-body">
        <div class="row" style="margin-bottom: 10px;">
            <div class="col-sm-6">
                <strong>Server Status:</strong>
                <span class="label label-{$midgardRuntimeStatusClass|default:'default'|escape}">{$midgardRuntimeStatusLabel|default:'Unknown'|escape}</span>
            </div>
            <div class="col-sm-6 text-right">
                <strong>Provisioning:</strong>
                <span class="label label-{if $midgardProvisionState eq 'failed'}danger{elseif $midgardProvisionState eq 'ready'}success{else}info{/if}">{$midgardProvisionStateLabel|default:'Installing'|escape}</span>
            </div>
        </div>

        {if $midgardIpv4Missing}
            <div class="alert alert-warning">{$midgardIpv4Warning|escape}</div>
        {/if}

        {if $midgardProvisionError}
            <div class="alert alert-danger">{$midgardProvisionError|escape}</div>
        {/if}

        <div class="row">
            <div class="col-md-6">
                <h4 class="m-t-0">Identity & Network</h4>
                <table class="table table-condensed">
                    <tr>
                        <th style="width: 40%;">Server Name</th>
                        <td>{$midgardServerName|default:'-'|escape}</td>
                    </tr>
                    <tr>
                        <th>Hostname</th>
                        <td>{$midgardServiceHostname|default:$domain|default:'-'|escape}</td>
                    </tr>
                    <tr>
                        <th>Service Status</th>
                        <td>{$midgardCurrentStatus|default:'-'|escape}</td>
                    </tr>
                    <tr>
                        <th>Primary IP</th>
                        <td>{$midgardPrimaryIp|escape}</td>
                    </tr>
                    <tr>
                        <th>IPv4</th>
                        <td>{$midgardPrimaryIpv4|default:'-'|escape}</td>
                    </tr>
                    <tr>
                        <th>IPv6</th>
                        <td>{$midgardPrimaryIpv6|default:'-'|escape}</td>
                    </tr>
                </table>
            </div>

            <div class="col-md-6">
                <h4 class="m-t-0">Server Specs</h4>
                <table class="table table-condensed">
                    <tr>
                        <th style="width: 45%;">CPU</th>
                        <td>{$midgardSpecs.cpu|default:0|escape}</td>
                    </tr>
                    <tr>
                        <th>Memory</th>
                        <td>{$midgardSpecs.memory_gb|default:0|escape} GB</td>
                    </tr>
                    <tr>
                        <th>Storage</th>
                        <td>{$midgardSpecs.disk_gb|default:0|escape} GB</td>
                    </tr>
                    <tr>
                        <th>Traffic</th>
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
                    <tr>
                        <th>OS Image ID</th>
                        <td>{$midgardSpecs.os_image_id|default:0|escape}</td>
                    </tr>
                </table>
            </div>
        </div>

        {if $midgardAssignedIpsArray|@count gt 0}
            <h4>Additional IP Addresses</h4>
            <ul class="list-unstyled" style="margin-bottom: 0;">
                {foreach $midgardAssignedIpsArray as $midgardIp}
                    <li><code>{$midgardIp|escape}</code></li>
                {/foreach}
            </ul>
        {/if}
    </div>
</div>

<div class="panel panel-default card mb-3">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Manage</h3>
    </div>
    <div class="panel-body card-body">
        <p>Use Midgard Console to manage power, networking, rebuilds, and other server operations.</p>

        {if $midgardSsoUrl}
            <a href="{$midgardSsoUrl|escape}" target="_blank" rel="noopener" class="btn btn-primary">Open Midgard Console</a>
            <a href="{$midgardSsoUrl|escape}" class="btn btn-default" style="margin-left: 8px;">Open In This Tab</a>
        {else}
            <div class="alert alert-warning" style="margin-bottom: 0;">Console SSO is currently unavailable. Please refresh in a moment.</div>
        {/if}
    </div>
</div>

<div class="panel panel-default card mb-3">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Billing Overview</h3>
    </div>
    <div class="panel-body card-body">
        <table class="table table-condensed" style="margin-bottom: 0;">
            <tr>
                <th style="width: 25%;">Product</th>
                <td style="width: 25%;">{$groupname|default:''|escape}{if $groupname && $product} - {/if}{$product|default:''|escape}</td>
                <th style="width: 25%;">Registration Date</th>
                <td style="width: 25%;">{$regdate|default:'-'|escape}</td>
            </tr>
            <tr>
                <th>Recurring Amount</th>
                <td>{$recurringamount|default:'-'|escape}</td>
                <th>Next Due Date</th>
                <td>{$nextduedate|default:'-'|escape}</td>
            </tr>
            <tr>
                <th>Billing Cycle</th>
                <td>{$billingcycle|default:'-'|escape}</td>
                <th>Payment Method</th>
                <td>{$paymentmethod|default:'-'|escape}</td>
            </tr>
        </table>
    </div>
</div>
