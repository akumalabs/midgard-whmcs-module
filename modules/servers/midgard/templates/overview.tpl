{assign var=midgardCurrentStatus value=$serviceStatus|default:$status}

<div class="panel card panel-default mb-3">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Server Overview</h3>
    </div>
    <div class="panel-body card-body p-4">
        {if $midgardProvisionStateLabel}
            <div class="alert alert-{if $midgardProvisionState eq 'failed'}danger{elseif $midgardProvisionState eq 'ready'}success{else}info{/if} mb-3">
                <strong>Status:</strong> {$midgardProvisionStateLabel|escape}
            </div>
        {/if}

        {if $midgardIpv4Missing}
            <div class="alert alert-warning mb-3">{$midgardIpv4Warning|escape}</div>
        {/if}

        {if $midgardProvisionError}
            <div class="alert alert-danger mb-3">{$midgardProvisionError|escape}</div>
        {/if}

        <div class="row">
            <div class="col-md-6">
                <div class="row p-1">
                    <div class="col-xs-4 col-4 text-right"><strong>Name:</strong></div>
                    <div class="col-xs-8 col-8">{$domain|default:'-'|escape}</div>
                </div>
                <div class="row p-1">
                    <div class="col-xs-4 col-4 text-right"><strong>Hostname:</strong></div>
                    <div class="col-xs-8 col-8">{$midgardServiceHostname|default:$domain|default:'-'|escape}</div>
                </div>
                <div class="row p-1">
                    <div class="col-xs-4 col-4 text-right"><strong>Memory:</strong></div>
                    <div class="col-xs-8 col-8">{$midgardSpecs.memory_gb|default:0|escape} GB</div>
                </div>
                <div class="row p-1">
                    <div class="col-xs-4 col-4 text-right"><strong>CPU:</strong></div>
                    <div class="col-xs-8 col-8">{$midgardSpecs.cpu|default:0|escape} vCPU</div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="row p-1">
                    <div class="col-xs-4 col-4 text-right"><strong>IPv4:</strong></div>
                    <div class="col-xs-8 col-8">{$midgardPrimaryIpv4|default:'-'|escape}</div>
                </div>
                <div class="row p-1">
                    <div class="col-xs-4 col-4 text-right"><strong>IPv6:</strong></div>
                    <div class="col-xs-8 col-8">{$midgardPrimaryIpv6|default:'-'|escape}</div>
                </div>
                <div class="row p-1">
                    <div class="col-xs-4 col-4 text-right"><strong>Storage:</strong></div>
                    <div class="col-xs-8 col-8">{$midgardSpecs.disk_gb|default:0|escape} GB</div>
                </div>
                <div class="row p-1">
                    <div class="col-xs-4 col-4 text-right"><strong>Traffic:</strong></div>
                    <div class="col-xs-8 col-8">{$midgardSpecs.bandwidth_tb|default:0|escape} TB</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="panel card panel-default mb-3">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Manage</h3>
    </div>
    <div class="panel-body card-body p-4">
        <p>Manage your server via the control panel. You will be automatically authenticated and the control panel will open in a new window.</p>

        {if $midgardSsoUrl}
            <a href="{$midgardSsoUrl|escape}" target="_blank" rel="noopener" class="btn btn-primary text-uppercase">Open Control Panel</a>
            <p class="mb-0 pt-3 small">Having trouble opening the control panel in a new window? <a href="{$midgardSsoUrl|escape}">Click here</a> to open in this window.</p>
        {else}
            <div class="alert alert-warning mb-0">SSO ticket is currently unavailable. Please try again shortly.</div>
        {/if}
    </div>
</div>

<div class="panel card panel-default mb-3">
    <div class="panel-heading card-header">
        <h3 class="panel-title card-title m-0">Billing Overview</h3>
    </div>
    <div class="panel-body card-body">
        <div class="row">
            <div class="col-lg-6">
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right"><strong>Product:</strong></div>
                    <div class="col-xs-6 col-6">{$groupname|default:''|escape}{if $groupname && $product} - {/if}{$product|default:''|escape}</div>
                </div>
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right"><strong>Recurring Amount:</strong></div>
                    <div class="col-xs-6 col-6">{$recurringamount|default:'-'|escape}</div>
                </div>
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right"><strong>Billing Cycle:</strong></div>
                    <div class="col-xs-6 col-6">{$billingcycle|default:'-'|escape}</div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right"><strong>Registration Date:</strong></div>
                    <div class="col-xs-6 col-6">{$regdate|default:'-'|escape}</div>
                </div>
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right"><strong>Next Due Date:</strong></div>
                    <div class="col-xs-6 col-6">{$nextduedate|default:'-'|escape}</div>
                </div>
                <div class="row p-2">
                    <div class="col-xs-6 col-6 text-right"><strong>Payment Method:</strong></div>
                    <div class="col-xs-6 col-6">{$paymentmethod|default:'-'|escape}</div>
                </div>
            </div>
        </div>
    </div>
</div>
