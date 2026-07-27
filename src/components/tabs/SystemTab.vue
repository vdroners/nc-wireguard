<template>
	<div class="nc-wg-tab">
		<ErrorBanner v-if="error" :message="error" @dismiss="error = ''" />
		<ErrorBanner
			v-if="systemUnavailableWarning"
			:message="systemUnavailableWarning"
			@dismiss="dismissSystemWarning = true" />
		<div class="card p-4 mb-4 nc-wg-server-card">
			<h3 class="nc-wg-chart-title">WireGuard server defaults (read-only)</h3>
			<p v-if="serverLoading" class="muted">Loading engine defaults…</p>
			<p v-else-if="serverUnavailable" class="muted">
				{{ serverMessage || 'Unavailable — break-glass loopback (127.0.0.1:51821) if needed.' }}
			</p>
			<dl v-else-if="server" class="nc-wg-server-dl">
				<div><dt>Host</dt><dd>{{ server.host || '—' }}</dd></div>
				<div><dt>UDP port</dt><dd>{{ server.port ?? '—' }}</dd></div>
				<div><dt>Interface</dt><dd>{{ server.interfaceName || '—' }} / {{ server.device || '—' }}</dd></div>
				<div><dt>IPv4 CIDR</dt><dd class="mono">{{ server.ipv4Cidr || '—' }}</dd></div>
				<div><dt>IPv6 CIDR</dt><dd class="mono">{{ server.ipv6Cidr || '—' }}</dd></div>
				<div><dt>MTU</dt><dd>{{ server.mtu ?? '—' }}</dd></div>
				<div><dt>Default DNS</dt><dd>{{ server.defaultDns || '— (per-peer)' }}</dd></div>
				<div><dt>Default AllowedIPs</dt><dd>{{ server.defaultAllowedIps || '— (per-peer)' }}</dd></div>
				<div><dt>Default keepalive</dt><dd>{{ server.defaultKeepalive ?? '— (per-peer)' }}</dd></div>
				<div><dt>Session timeout</dt><dd>{{ server.sessionTimeout != null ? server.sessionTimeout + 's' : '—' }}</dd></div>
			</dl>
			<ul v-if="serverNotes.length" class="nc-wg-server-notes muted">
				<li v-for="(n, i) in serverNotes" :key="i">{{ n }}</li>
			</ul>
		</div>
		<HistoryToolbar
			:time-value="hours"
			:time-options="timeOptions"
			:show-client="false"
			client-id=""
			@time-change="hours = $event; load()"
			@refresh="load" />
		<p v-if="coldStartHint" class="nc-wg-hint">{{ coldStartHint }}</p>
		<LoadingState v-if="loading" message="Loading system metrics…" />
		<div class="card p-4 mb-4">
			<h3 class="nc-wg-chart-title">CPU &amp; Memory</h3>
			<RateChart :datasets="cpuMemDatasets" y-format="percent" :y-max="100" :show-legend="true" :show-title="false" filled />
		</div>
		<div class="card p-4">
			<h3 class="nc-wg-chart-title">Network I/O</h3>
			<RateChart :datasets="netDatasets" :show-title="false" />
		</div>
	</div>
</template>

<script>
import { fetchSystem, fetchServerDefaults, extractApiError } from '../../services/dashboard-api.js'
import { buildSystemNetRates } from '../../utils/bandwidth-rates.js'
import ErrorBanner from '../common/ErrorBanner.vue'
import LoadingState from '../common/LoadingState.vue'
import RateChart from '../common/RateChart.vue'
import HistoryToolbar from '../HistoryToolbar.vue'
import { summaryStore } from '../../composables/useDashboardSummary.js'

export default {
	name: 'SystemTab',
	components: { ErrorBanner, LoadingState, RateChart, HistoryToolbar },
	props: {
		active: { type: Boolean, default: false },
	},
	data() {
		return {
			hours: 24,
			cpuMemDatasets: [],
			netDatasets: [],
			error: '',
			loading: false,
			loaded: false,
			coldStartHint: '',
			dismissSystemWarning: false,
			server: null,
			serverLoading: false,
			serverUnavailable: false,
			serverMessage: '',
			serverNotes: [],
			serverLoaded: false,
			timeOptions: [
				{ value: 1, label: '1 hour' },
				{ value: 6, label: '6 hours' },
				{ value: 24, label: '24 hours' },
				{ value: 168, label: '7 days' },
			],
		}
	},
	watch: {
		active(val) {
			if (val && !this.loaded) this.load()
			if (val && !this.serverLoaded) this.loadServer()
		},
	},
	computed: {
		systemUnavailableWarning() {
			if (this.dismissSystemWarning) return ''
			const h = summaryStore.health
			if (h?.host_metrics_ok === false) {
				return 'Host system metrics unavailable — mount /host/proc in cloud_app or check poller (see docs/ops/host-proc-mount.md).'
			}
			return ''
		},
	},
	methods: {
		async loadServer() {
			this.serverLoading = true
			this.serverUnavailable = false
			this.serverMessage = ''
			this.serverNotes = []
			try {
				const data = await fetchServerDefaults()
				if (!data || data.ok === false || data.unavailable) {
					this.serverUnavailable = true
					this.serverMessage = data?.message || ''
					this.server = null
				} else {
					this.server = data
					this.serverNotes = Array.isArray(data.notes) ? data.notes : []
				}
				this.serverLoaded = true
			} catch (_) {
				this.serverUnavailable = true
				this.serverMessage = 'Unavailable — break-glass loopback (127.0.0.1:51821) if needed.'
				this.serverLoaded = true
			} finally {
				this.serverLoading = false
			}
		},
		async load() {
			this.loading = true
			this.error = ''
			try {
				const data = await fetchSystem({ hours: this.hours })
				const ts = data.map(d => new Date(d.ts))
				this.cpuMemDatasets = [
					{
						label: 'CPU',
						data: ts.map((t, i) => ({ x: t, y: data[i].cpu_percent })),
						borderColor: '#dc2626',
						backgroundColor: '#dc262633',
					},
					{
						label: 'Memory',
						data: ts.map((t, i) => ({ x: t, y: data[i].mem_percent })),
						borderColor: '#2563eb',
						backgroundColor: '#2563eb33',
					},
				]
				const { netRx, netTx } = buildSystemNetRates(data)
				this.netDatasets = [
					{ label: 'Rx', data: netRx, borderColor: '#38bdf8', backgroundColor: '#38bdf833' },
					{ label: 'Tx', data: netTx, borderColor: '#fbbf24', backgroundColor: '#fbbf2433' },
				]
				this.coldStartHint = data.length < 2
					? 'Collecting metrics — charts populate after ~60 seconds of polling.'
					: ''
				this.loaded = true
			} catch (e) {
				this.error = extractApiError(e)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.nc-wg-server-dl {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
	gap: 0.5rem 1rem;
	margin: 0;
}
.nc-wg-server-dl div {
	display: flex;
	flex-direction: column;
	gap: 0.15rem;
}
.nc-wg-server-dl dt {
	font-size: 0.8rem;
	opacity: 0.75;
}
.nc-wg-server-dl dd {
	margin: 0;
}
.nc-wg-server-notes {
	margin: 0.75rem 0 0;
	padding-left: 1.1rem;
	font-size: 0.85rem;
}
</style>
