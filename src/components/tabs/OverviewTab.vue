<template>
	<div class="nc-wg-tab">
		<ErrorBanner v-if="error && !forbidden" :message="error" @dismiss="clearError" />
		<LoadingState v-if="loading && !summary" message="Loading overview…" />

		<div
			v-if="summary"
			class="nc-wg-stats-grid"
			:class="{ 'nc-wg-stats-grid--pulse': refreshing }">
			<div class="card p-4">
				<div class="stat-label">Connected</div>
				<div class="stat-value text-green">{{ summary.connectedCount }}</div>
			</div>
			<div class="card p-4">
				<div class="stat-label">Total Clients</div>
				<div class="stat-value">{{ summary.totalClients }}</div>
			</div>
			<div class="card p-4">
				<div class="stat-label">Total Download</div>
				<div class="stat-value text-sky">{{ fmtBytes(summary.totalRx) }}</div>
			</div>
			<div class="card p-4">
				<div class="stat-label">Total Upload</div>
				<div class="stat-value text-amber">{{ fmtBytes(summary.totalTx) }}</div>
			</div>
		</div>

		<HostMetricsStrip
			v-if="summary"
			class="nc-wg-stats-host"
			:cpu="summary.cpu"
			:mem="summary.mem"
			:disk="summary.disk"
			:refreshing="refreshing" />

		<div v-if="summary" class="nc-wg-overview-tools">
			<input
				v-model="filterText"
				type="search"
				class="nc-wg-search"
				placeholder="Filter by name or IP…"
				aria-label="Filter clients">
		</div>

		<PeerCardList
			v-if="summary && mobileLayout"
			:clients="displayClients"
			@select="$emit('show-config', $event)"
			@config="$emit('show-config', $event)" />

		<div v-if="summary && !mobileLayout" class="card nc-wg-table-wrap">
			<div class="nc-wg-table-scroll">
				<table class="nc-wg-table nc-wg-table--overview">
					<thead>
						<tr>
							<th class="nc-wg-sortable" @click="toggleSort('name')">
								Client {{ sortIndicator('name') }}
							</th>
							<th>IP</th>
							<th class="nc-wg-sortable" @click="toggleSort('status')">
								Status {{ sortIndicator('status') }}
							</th>
							<th class="nc-wg-sortable" @click="toggleSort('lastSeen')">
								Last Seen {{ sortIndicator('lastSeen') }}
							</th>
							<th class="nc-wg-sortable" @click="toggleSort('rx')">
								Rx {{ sortIndicator('rx') }}
							</th>
							<th>Tx</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<template v-for="c in displayClients">
							<tr
								:key="'row-' + c.id"
								class="nc-wg-peer-row"
								:class="peerRowClass(c)"
								@click="$emit('show-config', c)">
								<td>
									{{ c.name }}
									<span v-if="c.enabled === false" class="badge-muted badge-inline">Disabled</span>
									<span v-if="isExpiringSoon(c.expiresAt)" class="badge-warn badge-inline">Expiring</span>
								</td>
								<td class="mono">{{ c.ipv4Address }}</td>
								<td><span :class="c.connected ? 'badge-on' : 'badge-off'">{{ c.connected ? 'Online' : 'Offline' }}</span></td>
								<td class="muted">{{ timeAgo(c.latestHandshakeAt) }}</td>
								<td class="text-sky">{{ fmtBytes(c.transferRx) }}</td>
								<td class="text-amber">{{ fmtBytes(c.transferTx) }}</td>
								<td>
									<button
										type="button"
										class="nc-wg-btn nc-wg-btn--sm"
										@click.stop="toggleExpand(c.id)">
										{{ expandedId === c.id ? '▾' : '▸' }}
									</button>
								</td>
							</tr>
							<tr v-if="expandedId === c.id" :key="'exp-' + c.id" class="nc-wg-peer-expand">
								<td colspan="7">
									<div class="nc-wg-peer-expand__grid">
										<div><span class="muted">Endpoint</span> {{ c.endpoint || '—' }}</div>
										<div><span class="muted">Enabled</span> {{ c.enabled ? 'Yes' : 'No' }}</div>
										<div><span class="muted">Expires</span> {{ c.expiresAt ? fmtTime(c.expiresAt) : '—' }}</div>
										<button type="button" class="nc-wg-btn nc-wg-btn--sm nc-wg-btn--primary" @click.stop="$emit('show-config', c)">
											Config
										</button>
									</div>
								</td>
							</tr>
						</template>
						<tr v-if="!displayClients.length">
							<td colspan="7" class="nc-wg-empty">No clients match your filter.</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<p v-if="serverBootTime" class="nc-wg-uptime">
			Host uptime since {{ fmtTime(serverBootTime) }}
		</p>
	</div>
</template>

<script>
import { fmtBytes, fmtTime, timeAgo } from '../../utils/format.js'
import { isExpiringSoon, peerRowClass } from '../../utils/peer.js'
import { summaryStore, clearSummaryError } from '../../composables/useDashboardSummary.js'
import ErrorBanner from '../common/ErrorBanner.vue'
import LoadingState from '../common/LoadingState.vue'
import HostMetricsStrip from '../HostMetricsStrip.vue'
import PeerCardList from '../PeerCardList.vue'

const SORT_STORAGE_KEY = 'nc_wg_overview_sort'

export default {
	name: 'OverviewTab',
	components: { ErrorBanner, LoadingState, HostMetricsStrip, PeerCardList },
	props: {
		active: { type: Boolean, default: false },
		mobileLayout: { type: Boolean, default: false },
	},
	data() {
		const saved = typeof sessionStorage !== 'undefined'
			? sessionStorage.getItem(SORT_STORAGE_KEY)
			: null
		let sortKey = 'name'
		let sortDir = 1
		if (saved) {
			try {
				const p = JSON.parse(saved)
				sortKey = p.key || sortKey
				sortDir = p.dir || sortDir
			} catch (_) { /* ignore */ }
		}
		return {
			store: summaryStore,
			filterText: '',
			sortKey,
			sortDir,
			expandedId: null,
		}
	},
	computed: {
		summary() { return this.store.summary },
		loading() { return this.store.loading },
		refreshing() { return this.store.refreshing },
		error() { return this.store.error },
		forbidden() { return this.store.forbidden },
		serverBootTime() { return this.store.serverBootTime },
		displayClients() {
			const list = [...(this.summary?.clients || [])]
			const q = this.filterText.trim().toLowerCase()
			const filtered = q
				? list.filter(c => (c.name || '').toLowerCase().includes(q)
					|| (c.ipv4Address || '').toLowerCase().includes(q))
				: list
			return filtered.sort((a, b) => this.compareClients(a, b))
		},
	},
	methods: {
		fmtBytes,
		fmtTime,
		timeAgo,
		isExpiringSoon,
		peerRowClass,
		clearError() {
			clearSummaryError()
		},
		toggleExpand(id) {
			this.expandedId = this.expandedId === id ? null : id
		},
		sortIndicator(key) {
			if (this.sortKey !== key) return ''
			return this.sortDir > 0 ? '↑' : '↓'
		},
		toggleSort(key) {
			if (this.sortKey === key) {
				this.sortDir *= -1
			} else {
				this.sortKey = key
				this.sortDir = 1
			}
			if (typeof sessionStorage !== 'undefined') {
				sessionStorage.setItem(SORT_STORAGE_KEY, JSON.stringify({ key: this.sortKey, dir: this.sortDir }))
			}
		},
		compareClients(a, b) {
			const dir = this.sortDir
			switch (this.sortKey) {
			case 'status':
				return dir * ((a.connected ? 1 : 0) - (b.connected ? 1 : 0))
			case 'lastSeen': {
				const ta = a.latestHandshakeAt ? new Date(a.latestHandshakeAt).getTime() : 0
				const tb = b.latestHandshakeAt ? new Date(b.latestHandshakeAt).getTime() : 0
				return dir * (ta - tb)
			}
			case 'rx':
				return dir * ((a.transferRx || 0) - (b.transferRx || 0))
			default:
				return dir * (a.name || '').localeCompare(b.name || '')
			}
		},
	},
}
</script>
