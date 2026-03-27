<style>
.midgard-client-area {
    display: grid;
    gap: 16px;
}

.midgard-grid {
    display: grid;
    gap: 16px;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
}

.midgard-card {
    background: linear-gradient(180deg, #0f172a 0%, #111827 100%);
    color: #e5e7eb;
    border: 1px solid #334155;
    border-radius: 14px;
    padding: 18px;
    box-shadow: 0 10px 30px rgba(2, 6, 23, 0.35);
}

.midgard-card-title {
    margin: 0 0 12px;
    font-size: 14px;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    color: #93c5fd;
}

.midgard-state {
    display: inline-flex;
    align-items: center;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 700;
}

.midgard-state-ready { background: #052e1b; color: #86efac; border: 1px solid #166534; }
.midgard-state-failed { background: #450a0a; color: #fca5a5; border: 1px solid #7f1d1d; }
.midgard-state-installing { background: #172554; color: #bfdbfe; border: 1px solid #1d4ed8; }

.midgard-warning {
    margin: 0;
    background: #3f2a00;
    border: 1px solid #854d0e;
    color: #fde68a;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 13px;
}

.midgard-error {
    margin: 10px 0 0;
    background: #3f1212;
    border: 1px solid #7f1d1d;
    color: #fecaca;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 13px;
}

.midgard-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.midgard-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    border: 1px solid #334155;
    background: #111827;
    color: #cbd5e1;
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 600;
}

.midgard-badge-ipv4 { border-color: #1d4ed8; color: #bfdbfe; }
.midgard-badge-ipv6 { border-color: #6d28d9; color: #ddd6fe; }

.midgard-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 8px;
}

.midgard-list li {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    border-bottom: 1px dashed #334155;
    padding-bottom: 7px;
    font-size: 13px;
}

.midgard-list li:last-child {
    border-bottom: 0;
    padding-bottom: 0;
}

.midgard-muted {
    margin: 0;
    color: #94a3b8;
    font-size: 13px;
}

.midgard-sso-btn {
    display: inline-block;
    background: linear-gradient(90deg, #2563eb 0%, #7c3aed 100%);
    color: #f8fafc;
    border: 0;
    border-radius: 10px;
    padding: 10px 14px;
    text-decoration: none;
    font-weight: 700;
}
</style>

<div class="midgard-client-area">
    <section class="midgard-card">
        <h3 class="midgard-card-title">Provisioning Status</h3>
        <span class="midgard-state {$midgardProvisionStateClass|escape}">
            {$midgardProvisionStateLabel|escape}
        </span>

        {if $midgardIpv4Missing}
            <p class="midgard-warning" style="margin-top:10px;">{$midgardIpv4Warning|escape}</p>
        {/if}

        {if $midgardProvisionError}
            <p class="midgard-error">{$midgardProvisionError|escape}</p>
        {/if}
    </section>

    <div class="midgard-grid">
        <section class="midgard-card">
            <h3 class="midgard-card-title">Network</h3>
            <div class="midgard-badges" style="margin-bottom:12px;">
                {if $midgardPrimaryIpv4}
                    <span class="midgard-badge midgard-badge-ipv4">Primary IPv4: {$midgardPrimaryIpv4|escape}</span>
                {else}
                    <span class="midgard-badge midgard-badge-ipv4">Primary IPv4: Not assigned</span>
                {/if}

                {if $midgardPrimaryIpv6}
                    <span class="midgard-badge midgard-badge-ipv6">Primary IPv6: {$midgardPrimaryIpv6|escape}</span>
                {else}
                    <span class="midgard-badge midgard-badge-ipv6">Primary IPv6: Not assigned</span>
                {/if}
            </div>

            {if $midgardAddresses|@count > 0}
                <ul class="midgard-list">
                    {foreach $midgardAddresses as $address}
                        <li>
                            <span>{$address.type|upper|escape}</span>
                            <span>
                                {$address.address|escape}
                                {if $address.is_primary}<strong>(Primary)</strong>{/if}
                            </span>
                        </li>
                    {/foreach}
                </ul>
            {else}
                <p class="midgard-muted">No IP addresses are currently assigned to this server.</p>
            {/if}
        </section>

        <section class="midgard-card">
            <h3 class="midgard-card-title">Server Specs</h3>
            <ul class="midgard-list">
                <li><span>CPU</span><span>{$midgardSpecs.cpu|escape} vCPU</span></li>
                <li><span>RAM</span><span>{$midgardSpecs.memory_gb|escape} GB</span></li>
                <li><span>Disk</span><span>{$midgardSpecs.disk_gb|escape} GB</span></li>
                <li><span>Bandwidth</span><span>{$midgardSpecs.bandwidth_tb|escape} TB</span></li>
                <li><span>Backup Limit</span><span>{$midgardSpecs.backup_limit|escape}</span></li>
                <li><span>Snapshot Limit</span><span>{$midgardSpecs.snapshot_limit|escape}</span></li>
                <li><span>OS Image ID</span><span>{$midgardSpecs.os_image_id|escape}</span></li>
            </ul>
        </section>
    </div>

    <section class="midgard-card">
        <h3 class="midgard-card-title">Panel Access</h3>
        {if $midgardSsoUrl}
            <a href="{$midgardSsoUrl|escape}" target="_blank" rel="noopener" class="midgard-sso-btn">Open in Panel (SSO)</a>
        {else}
            <p class="midgard-muted">SSO ticket is currently unavailable.</p>
        {/if}
    </section>
</div>
