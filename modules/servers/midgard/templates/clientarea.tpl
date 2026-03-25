<div class="midgard-client-area" style="display:grid;gap:16px;">
    <section style="border:1px solid #d9dee8;border-radius:8px;padding:16px;">
        <h3 style="margin:0 0 8px 0;">Provisioning Status</h3>
        <p class="{$midgardProvisionStateClass|escape}" style="margin:0;font-weight:600;">{$midgardProvisionStateLabel|escape}</p>
        {if $midgardProvisionError}
            <p style="margin:8px 0 0 0;color:#b42318;">{$midgardProvisionError|escape}</p>
        {/if}
    </section>

    <section style="border:1px solid #d9dee8;border-radius:8px;padding:16px;">
        <h3 style="margin:0 0 8px 0;">Server Specs</h3>
        <ul style="margin:0;padding-left:18px;line-height:1.8;">
            <li>CPU: {$midgardSpecs.cpu|escape} vCPU</li>
            <li>RAM: {$midgardSpecs.memory_gb|escape} GB</li>
            <li>Disk: {$midgardSpecs.disk_gb|escape} GB</li>
            <li>Bandwidth: {$midgardSpecs.bandwidth_tb|escape} TB</li>
            <li>Backup Limit: {$midgardSpecs.backup_limit|escape}</li>
            <li>Snapshot Limit: {$midgardSpecs.snapshot_limit|escape}</li>
            <li>OS Image ID: {$midgardSpecs.os_image_id|escape}</li>
        </ul>
    </section>

    <section style="border:1px solid #d9dee8;border-radius:8px;padding:16px;">
        <h3 style="margin:0 0 8px 0;">Panel Access</h3>
        {if $midgardSsoUrl}
            <a href="{$midgardSsoUrl|escape}" target="_blank" rel="noopener" style="display:inline-block;background:#0b63f6;color:#fff;padding:10px 14px;border-radius:6px;text-decoration:none;font-weight:600;">Open in Panel (SSO)</a>
        {else}
            <p style="margin:0;color:#667085;">SSO ticket is currently unavailable.</p>
        {/if}
    </section>
</div>
