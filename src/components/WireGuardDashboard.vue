<template>
	<div class="nc-wg-dashboard">
		<div v-if="!enabled" class="nc-wg-disabled card">
			<h2>WireGuard dashboard disabled</h2>
			<p>Enable the dashboard in NC WireGuard admin settings.</p>
			<a class="nc-wg-admin-link" :href="adminSettingsUrl">Open NC WireGuard settings</a>
		</div>
		<div v-else-if="store.forbidden" class="nc-wg-forbidden card">
			<h2>Administrators only</h2>
			<p>WireGuard monitoring is restricted to Nextcloud administrators.</p>
		</div>
		<template v-else>
			<TabBar
				:tabs="tabsWithBadges"
				:active-tab="activeTab"
				:mobile-layout="mobileLayout"
				:overview-badge="connectedCount"
				@change="setTab" />
			<OverviewTab
				v-show="activeTab === 'overview'"
				:active="activeTab === 'overview'"
				:mobile-layout="mobileLayout"
				@show-config="openConfig" />
			<BandwidthTab
				v-show="activeTab === 'bandwidth'"
				:active="activeTab === 'bandwidth'"
				:clients="store.clients" />
			<ConnectionsTab
				v-show="activeTab === 'connections'"
				:active="activeTab === 'connections'"
				:clients="store.clients" />
			<MapTab
				v-show="activeTab === 'map'"
				:active="activeTab === 'map'"
				@geo-count="geoCount = $event" />
			<SystemTab v-show="activeTab === 'system'" :active="activeTab === 'system'" />
			<PeerConfigModal
				:open="configModal.open"
				:client-id="configModal.clientId"
				:client-name="configModal.clientName"
				:wg-easy-url="wgEasyUrl"
				@close="configModal.open = false" />
		</template>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import TabBar from './TabBar.vue'
import OverviewTab from './tabs/OverviewTab.vue'
import BandwidthTab from './tabs/BandwidthTab.vue'
import ConnectionsTab from './tabs/ConnectionsTab.vue'
import MapTab from './tabs/MapTab.vue'
import SystemTab from './tabs/SystemTab.vue'
import PeerConfigModal from './common/PeerConfigModal.vue'
import {
	summaryStore,
	startSummaryPolling,
	stopSummaryPolling,
	getConnectedCount,
} from '../composables/useDashboardSummary.js'

const TAB_IDS = ['overview', 'bandwidth', 'connections', 'map', 'system']
const MOBILE_BREAK = 768

export default {
	name: 'WireGuardDashboard',
	components: {
		TabBar,
		OverviewTab,
		BandwidthTab,
		ConnectionsTab,
		MapTab,
		SystemTab,
		PeerConfigModal,
	},
	props: {
		enabled: { type: Boolean, default: true },
		wgEasyUrl: { type: String, default: '' },
	},
	data() {
		const hash = (typeof window !== 'undefined' && window.location.hash.replace('#', '')) || 'overview'
		return {
			store: summaryStore,
			tabs: [
				{ id: 'overview', label: 'Overview' },
				{ id: 'bandwidth', label: 'Bandwidth' },
				{ id: 'connections', label: 'Connections' },
				{ id: 'map', label: 'Map' },
				{ id: 'system', label: 'System' },
			],
			activeTab: TAB_IDS.includes(hash) ? hash : 'overview',
			configModal: { open: false, clientId: null, clientName: '' },
			mobileLayout: false,
			geoCount: null,
			resizeHandler: null,
		}
	},
	computed: {
		adminSettingsUrl() {
			return generateUrl('/settings/admin/nc_wireguard')
		},
		connectedCount() {
			return getConnectedCount()
		},
		tabsWithBadges() {
			return this.tabs.map(t => {
				if (t.id === 'overview' && this.connectedCount != null) {
					return { ...t, badge: this.connectedCount }
				}
				if (t.id === 'map' && this.geoCount != null) {
					return { ...t, badge: this.geoCount }
				}
				return t
			})
		},
	},
	mounted() {
		if (this.enabled) {
			startSummaryPolling()
		}
		this.updateMobileLayout()
		this.resizeHandler = () => this.updateMobileLayout()
		window.addEventListener('hashchange', this.onHashChange)
		window.addEventListener('resize', this.resizeHandler)
	},
	beforeDestroy() {
		stopSummaryPolling()
		window.removeEventListener('hashchange', this.onHashChange)
		if (this.resizeHandler) {
			window.removeEventListener('resize', this.resizeHandler)
		}
	},
	methods: {
		updateMobileLayout() {
			this.mobileLayout = typeof window !== 'undefined' && window.innerWidth < MOBILE_BREAK
		},
		setTab(id) {
			this.activeTab = id
			if (typeof window !== 'undefined') {
				window.location.hash = id
			}
		},
		onHashChange() {
			const id = window.location.hash.replace('#', '')
			if (TAB_IDS.includes(id)) this.activeTab = id
		},
		openConfig(client) {
			this.configModal = {
				open: true,
				clientId: client.id,
				clientName: client.name,
			}
		},
	},
}
</script>
