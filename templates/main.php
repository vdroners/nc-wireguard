<?php
/** @var array $_ */
?>
<div
	id="nc-wireguard-root"
	class="nc-gcs-app-shell nc-gcs-app-shell--nc_wireguard"
	data-app-id="nc_wireguard"
	data-enabled="<?php echo !empty($_['enabled']) ? '1' : '0'; ?>"
	data-wg-easy-url="<?php echo htmlspecialchars((string)($_['wg_easy_admin_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
	data-hide-wg-easy="<?php echo !empty($_['hide_wg_easy_admin_link']) ? '1' : '0'; ?>">
	<noscript>
		<div style="padding:24px;font-family:system-ui,sans-serif;">
			<h1>NC WireGuard</h1>
			<p>JavaScript is required to view the WireGuard monitoring dashboard.</p>
		</div>
	</noscript>
</div>
