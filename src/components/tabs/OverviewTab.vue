<template>
	<div class="nc-wg-tab">
		<ErrorBanner v-if="error" :message="error" @dismiss="error = ''" />
		<LoadingState v-if="loading && !summary" message="Loading overview…" />

		<div v-if="health" class="nc-wg-health card">
			<span>Sidecar {{ health.sidecar_version || '?' }}</span>
			<span :class="health.sidecar_ok ? 'badge-on' : 'badge-off'">
				{{ health.sidecar_ok ? 'Sidecar OK' : 'Sidecar down' }}
			</span>
			<span :class="health.wg_easy_ok ? 'badge-on' : 'badge-off'">
				{{ health.wg_easy_ok ? 'wg-easy OK' : 'wg-easy down' }}
			</span>
		</div>

		<div v-if="summary" class="nc-wg-stats-grid">
			<div class="card p-4"><div class="stat-label">Connected</div><div class="stat-value text-green">{{ summary.connectedCount }}</div></div>
			<div class="card p-4"><div class="stat-label">Total Clients</div><div class="stat-value">{{ summary.totalClients }}</div></div>
			<div class="card p-4"><div class="stat-label">Total Download</div><div class="stat-value text-sky">{{ fmtBytes(summary.totalRx) }}</div></div>
			<div class="card p-4"><div class="stat-label">Total Upload</div><div class="stat-value text-amber">{{ fmtBytes(summary.totalTx) }}</div></div>
			<div class="card p-4"><div class="stat-label">CPU</div><div class="stat-value">{{ summary.cpu?.toFixed(1) }}%</div></div>
			<div class="card p-4"><div class="stat-label">Memory</div><div class="stat-value">{{ summary.mem?.toFixed(1) }}%</div></div>
			<div class="card p-4"><div class="stat-label">Disk</div><div class="stat-value">{{ summary.disk?.toFixed(1) }}%</div></div>
		</div>

		<div v-if="summary" class="card nc-wg-table-wrap">
			<div class="nc-wg-table-scroll">
				<table class="nc-wg-table">
					<thead>
						<tr>
							<th>Client</th><th>IP</th><th>Status</th><th>Enabled</th><th>Expires</th>
							<th>Endpoint</th><th>Rx</th><th>Tx</th><th>Last Seen</th><th></th>
						</tr>
					</thead>
					<tbody>
						<tr v-if="!summary.clients?.length">
							<td colspan="10" class="nc-wg-empty">No clients configured in wg-easy.</td>
						</tr>
						<tr v-for="c in summary.clients" :key="c.id">
							<td>{{ c.name }}</td>
							<td class="mono">{{ c.ipv4Address }}</td>
							<td><span :class="c.connected ? 'badge-on' : 'badge-off'">{{ c.connected ? 'Online' : 'Offline' }}</span></td>
							<td>{{ c.enabled ? 'Yes' : 'No' }}</td>
							<td class="mono-xs">{{ c.expiresAt ? fmtTime(c.expiresAt) : '—' }}</td>
							<td class="mono">{{ c.endpoint || '—' }}</td>
							<td class="text-sky">{{ fmtBytes(c.transferRx) }}</td>
							<td class="text-amber">{{ fmtBytes(c.transferTx) }}</td>
							<td class="muted">{{ timeAgo(c.latestHandshakeAt) }}</td>
							<td><button type="button" class="nc-wg-btn nc-wg-btn--sm" @click="$emit('show-config', c)">Config</button></td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		<p v-if="lastUpdate" class="nc-wg-last-update">Updated {{ lastUpdate }}</p>
	</div>
</template>

<script>
import { fmtBytes, fmtTime, timeAgo } from '../../utils/format.js'
import { fetchSummary, fetchStatus, extractApiError } from '../../services/dashboard-api.js'
import ErrorBanner from '../common/ErrorBanner.vue'
import LoadingState from '../common/LoadingState.vue'

export default {
	name: 'OverviewTab',
	components: { ErrorBanner, LoadingState },
	props: {
		active: { type: Boolean, default: false },
	},
	data() {
		return {
			summary: null,
			health: null,
			error: '',
			loading: false,
			lastUpdate: '',
			pollTimer: null,
		}
	},
	watch: {
		active: {
			immediate: true,
			handler(val) {
				if (val) this.startPoll()
				else this.stopPoll()
			},
		},
	},
	beforeDestroy() {
		this.stopPoll()
	},
	methods: {
		fmtBytes,
		fmtTime,
		timeAgo,
		startPoll() {
			this.loadAll()
			if (!this.pollTimer) {
				this.pollTimer = setInterval(() => this.loadAll(), 15000)
			}
		},
		stopPoll() {
			if (this.pollTimer) {
				clearInterval(this.pollTimer)
				this.pollTimer = null
			}
		},
		async loadAll() {
			await Promise.all([this.loadSummary(), this.loadHealth()])
		},
		async loadSummary() {
			this.loading = true
			try {
				const d = await fetchSummary()
				if (d?.error) {
					this.error = d.error
					return
				}
				this.summary = d
				this.error = ''
				this.lastUpdate = new Date().toLocaleTimeString()
				this.$emit('clients-updated', d.clients || [])
			} catch (e) {
				this.error = extractApiError(e)
			} finally {
				this.loading = false
			}
		},
		async loadHealth() {
			try {
				this.health = await fetchStatus()
			} catch (_) {
				// non-fatal
			}
		},
	},
}
</script>
