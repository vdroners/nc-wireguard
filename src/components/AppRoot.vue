<template>
	<NcGcsAppShell
		:app-id="'nc_wireguard'"
		title="NC WireGuard"
		accent="#dc2626"
		:subtitle="subtitle">
		<template #banner-extra>
			<div class="nc-wg-banner-extra">
				<span v-if="statusLine" class="nc-wg-banner-chip">{{ statusLine }}</span>
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
	</NcGcsAppShell>
</template>

<script>
import NcGcsAppShell from '@/components/common/NcGcsAppShell.vue'
import WireGuardDashboard from './WireGuardDashboard.vue'
import { summaryStore } from '../composables/useDashboardSummary.js'

export default {
	name: 'AppRoot',
	components: { NcGcsAppShell, WireGuardDashboard },
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
			if (total > 0) {
				return `vpn-vdroners.ddns.net · ${total} peer${total === 1 ? '' : 's'}`
			}
			return 'VPN server monitoring'
		},
		statusLine() {
			const s = this.store.summary
			if (!s) return ''
			return `${s.connectedCount}/${s.totalClients} online`
		},
		healthChip() {
			const h = this.store.health
			if (!h) return null
			if (h.sidecar_ok && h.wg_easy_ok) {
				return { ok: true, text: 'Sidecar OK · wg-easy OK' }
			}
			const parts = []
			if (!h.sidecar_ok) parts.push('Sidecar down')
			if (!h.wg_easy_ok) parts.push('wg-easy down')
			return { ok: false, text: parts.join(' · ') }
		},
	},
}
</script>
