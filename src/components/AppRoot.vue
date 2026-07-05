<template>
	<NcAppShell
		:app-id="'nc_wireguard'"
		title="NC WireGuard"
		accent="#dc2626"
		:subtitle="subtitle">
		<template #banner-extra>
			<div class="nc-wg-banner-extra">
				<span v-if="statusLine" class="nc-wg-banner-chip">{{ statusLine }}</span>
				<span v-if="backendChip" class="nc-wg-banner-chip nc-wg-banner-chip--muted">
					{{ backendChip }}
				</span>
				<span v-if="healthChip" class="nc-wg-banner-chip" :class="healthChip.ok ? 'nc-wg-banner-chip--ok' : 'nc-wg-banner-chip--err'">
					{{ healthChip.text }}
				</span>
				<span v-if="store.lastUpdate" class="nc-wg-banner-chip nc-wg-banner-chip--muted">
					Updated {{ store.lastUpdate }}
				</span>
				<a
					v-if="wgEasyUrl"
					class="nc-wg-admin-link"
					:href="wgEasyUrl"
					target="_blank"
					rel="noopener">
					wg-easy admin
				</a>
			</div>
		</template>
		<WireGuardDashboard :enabled="enabled" :wg-easy-url="wgEasyUrl" />
		<p class="nc-wg-disclaimer">
			Not affiliated with or endorsed by WireGuard or wg-easy.
			WireGuard is a registered trademark of Jason A. Donenfeld.
		</p>
	</NcAppShell>
</template>

<script>
import NcAppShell from './NcAppShell.vue'
import WireGuardDashboard from './WireGuardDashboard.vue'
import { summaryStore } from '../composables/useDashboardSummary.js'

export default {
	name: 'AppRoot',
	components: { NcAppShell, WireGuardDashboard },
	props: {
		enabled: { type: Boolean, default: true },
		wgEasyUrl: { type: String, default: '' },
	},
	data() {
		return { store: summaryStore }
	},
	computed: {
		subtitle() {
			const total = this.store.summary?.totalClients ?? this.store.clients.length
			if (this.wgEasyUrl) {
				try {
					const host = new URL(this.wgEasyUrl).host
					if (total > 0) {
						return `${host} · ${total} peer${total === 1 ? '' : 's'}`
					}
					return host
				} catch {
					// fall through
				}
			}
			if (total > 0) {
				return `${total} peer${total === 1 ? '' : 's'}`
			}
			return 'VPN server monitoring (wg-easy)'
		},
		statusLine() {
			const s = this.store.summary
			if (!s) return ''
			return `${s.connectedCount}/${s.totalClients} online`
		},
		backendChip() {
			const h = this.store.health
			if (!h || !h.is_admin) return ''
			return 'Native backend'
		},
		healthChip() {
			const h = this.store.health
			if (!h || !h.is_admin) return null
			if (h.native_ok && h.wg_easy_ok) {
				return { ok: true, text: 'Native OK · wg-easy OK' }
			}
			const parts = []
			if (!h.poller_ok) parts.push('Poller stale')
			if (!h.wg_easy_ok) parts.push('wg-easy down')
			return { ok: false, text: parts.join(' · ') || 'Native degraded' }
		},
	},
}
</script>
