<template>
	<div class="nc-wg-dashboard">
		<div v-if="!enabled" class="nc-wg-disabled card">
			<h2>WireGuard dashboard disabled</h2>
			<p>Enable the dashboard in NC WireGuard admin settings.</p>
			<a class="nc-wg-admin-link" :href="adminSettingsUrl">Open NC WireGuard settings</a>
		</div>
		<div v-else-if="store.forbidden" class="nc-wg-forbidden card">
			<h2>Administrators only</h2>
			<p>WireGuard peer control is restricted to Nextcloud administrators.</p>
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
				ref="overview"
				:active="activeTab === 'overview'"
				:mobile-layout="mobileLayout"
				@show-config="openConfig"
				@new-peer="openForm(null)"
				@edit-peer="openForm"
				@toggle-peer="onTogglePeer"
				@delete-peer="onDeletePeer"
				@apply-field-preset="onApplyFieldPreset" />
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
				:wg-easy-url="hideWgEasy ? '' : wgEasyUrl"
				@close="configModal.open = false"
				@edit="onEditFromConfig" />
			<PeerFormModal
				:open="formModal.open"
				:peer="formModal.peer"
				@close="formModal.open = false"
				@saved="onPeerSaved" />
			<div v-if="actionToast" class="nc-wg-toast">{{ actionToast }}</div>
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
import PeerFormModal from './common/PeerFormModal.vue'
import {
	enablePeer,
	disablePeer,
	deletePeer,
	updatePeer,
	extractApiError,
	PRESET_FIELD,
} from '../services/dashboard-api.js'
import {
	summaryStore,
	startSummaryPolling,
	stopSummaryPolling,
	getConnectedCount,
	refreshSummaryNow,
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
		PeerFormModal,
	},
	props: {
		enabled: { type: Boolean, default: true },
		wgEasyUrl: { type: String, default: '' },
		hideWgEasy: { type: Boolean, default: true },
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
			formModal: { open: false, peer: null },
			mobileLayout: false,
			geoCount: null,
			resizeHandler: null,
			actionToast: '',
			toastTimer: null,
			busyId: null,
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
		if (this.toastTimer) clearTimeout(this.toastTimer)
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
		showToast(msg) {
			this.actionToast = msg
			if (this.toastTimer) clearTimeout(this.toastTimer)
			this.toastTimer = setTimeout(() => { this.actionToast = '' }, 2500)
		},
		openConfig(client) {
			this.configModal = {
				open: true,
				clientId: client.id,
				clientName: client.name,
			}
		},
		openForm(peer) {
			this.formModal = { open: true, peer: peer || null }
		},
		onEditFromConfig() {
			const id = this.configModal.clientId
			const peer = (this.store.clients || []).find(c => c.id === id)
			if (peer) {
				this.configModal.open = false
				this.openForm(peer)
			}
		},
		async onPeerSaved() {
			this.showToast('Peer saved')
			await refreshSummaryNow()
		},
		async onTogglePeer(client) {
			if (this.busyId) return
			this.busyId = client.id
			try {
				if (client.enabled === false) {
					await enablePeer(client.id)
					this.showToast(`Enabled ${client.name}`)
				} else {
					await disablePeer(client.id)
					this.showToast(`Disabled ${client.name}`)
				}
				await refreshSummaryNow()
			} catch (e) {
				this.showToast(extractApiError(e))
			} finally {
				this.busyId = null
			}
		},
		async onDeletePeer(client) {
			if (this.busyId) return
			const ok = window.confirm(
				`Delete peer "${client.name}"?\nPrefer Disable for field peers. This cannot be undone.`,
			)
			if (!ok) return
			this.busyId = client.id
			try {
				await deletePeer(client.id)
				this.showToast(`Deleted ${client.name}`)
				if (this.configModal.clientId === client.id) {
					this.configModal.open = false
				}
				await refreshSummaryNow()
			} catch (e) {
				this.showToast(extractApiError(e))
			} finally {
				this.busyId = null
			}
		},
		async onApplyFieldPreset(peers) {
			if (this.busyId || !Array.isArray(peers) || !peers.length) return
			const names = peers.map(p => p.name).join(', ')
			const ok = window.confirm(
				`Apply Field preset to ${peers.length} peer(s)?\n`
				+ `AllowedIPs ${PRESET_FIELD.allowedIps}, keepalive ${PRESET_FIELD.persistentKeepalive}.\n`
				+ `Skips peer named Server.\n\n${names}`,
			)
			if (!ok) return
			this.busyId = 'bulk'
			let okCount = 0
			const fails = []
			try {
				for (const peer of peers) {
					if (String(peer.name || '').trim().toLowerCase() === 'server') {
						continue
					}
					try {
						await updatePeer(peer.id, {
							name: peer.name,
							allowedIps: PRESET_FIELD.allowedIps,
							persistentKeepalive: PRESET_FIELD.persistentKeepalive,
							mtu: PRESET_FIELD.mtu,
							dns: PRESET_FIELD.dns || null,
						})
						okCount++
					} catch (e) {
						fails.push(`${peer.name}: ${extractApiError(e)}`)
					}
				}
				await refreshSummaryNow()
				if (this.$refs.overview?.clearSelection) {
					this.$refs.overview.clearSelection()
				}
				if (fails.length === 0) {
					this.showToast(`Field preset applied to ${okCount} peer(s)`)
				} else {
					this.showToast(`Applied ${okCount}; failed ${fails.length}`)
				}
			} finally {
				this.busyId = null
			}
		},
	},
}
</script>
