<template>
	<div class="nc-wg-dashboard">
		<div v-if="!enabled" class="nc-wg-disabled card">
			<h2>WireGuard dashboard disabled</h2>
			<p>Enable the dashboard in NC WireGuard admin settings.</p>
		</div>
		<template v-else>
			<div class="nc-wg-tabs" role="tablist">
				<button
					v-for="t in tabs"
					:key="t.id"
					type="button"
					class="nc-wg-tab-btn"
					:class="{ active: activeTab === t.id }"
					role="tab"
					:aria-selected="activeTab === t.id"
					@click="setTab(t.id)">
					{{ t.label }}
				</button>
			</div>
			<OverviewTab
				v-show="activeTab === 'overview'"
				:active="activeTab === 'overview'"
				@clients-updated="onClientsUpdated"
				@show-config="openConfig" />
			<BandwidthTab v-show="activeTab === 'bandwidth'" :active="activeTab === 'bandwidth'" :clients="clients" />
			<ConnectionsTab v-show="activeTab === 'connections'" :active="activeTab === 'connections'" :clients="clients" />
			<MapTab v-show="activeTab === 'map'" :active="activeTab === 'map'" />
			<SystemTab v-show="activeTab === 'system'" :active="activeTab === 'system'" />
			<PeerConfigModal
				:open="configModal.open"
				:client-id="configModal.clientId"
				:client-name="configModal.clientName"
				@close="configModal.open = false" />
		</template>
	</div>
</template>

<script>
import OverviewTab from './tabs/OverviewTab.vue'
import BandwidthTab from './tabs/BandwidthTab.vue'
import ConnectionsTab from './tabs/ConnectionsTab.vue'
import MapTab from './tabs/MapTab.vue'
import SystemTab from './tabs/SystemTab.vue'
import PeerConfigModal from './common/PeerConfigModal.vue'

const TAB_IDS = ['overview', 'bandwidth', 'connections', 'map', 'system']

export default {
	name: 'WireGuardDashboard',
	components: {
		OverviewTab,
		BandwidthTab,
		ConnectionsTab,
		MapTab,
		SystemTab,
		PeerConfigModal,
	},
	props: {
		enabled: { type: Boolean, default: true },
	},
	data() {
		const hash = (typeof window !== 'undefined' && window.location.hash.replace('#', '')) || 'overview'
		return {
			tabs: [
				{ id: 'overview', label: 'Overview' },
				{ id: 'bandwidth', label: 'Bandwidth' },
				{ id: 'connections', label: 'Connections' },
				{ id: 'map', label: 'Map' },
				{ id: 'system', label: 'System' },
			],
			activeTab: TAB_IDS.includes(hash) ? hash : 'overview',
			clients: [],
			configModal: { open: false, clientId: null, clientName: '' },
		}
	},
	mounted() {
		window.addEventListener('hashchange', this.onHashChange)
	},
	beforeDestroy() {
		window.removeEventListener('hashchange', this.onHashChange)
	},
	methods: {
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
		onClientsUpdated(list) {
			this.clients = list
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
