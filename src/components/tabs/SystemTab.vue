<template>
	<div class="nc-wg-tab">
		<ErrorBanner v-if="error" :message="error" @dismiss="error = ''" />
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
import { fetchSystem, extractApiError } from '../../services/dashboard-api.js'
import { buildSystemNetRates } from '../../utils/bandwidth-rates.js'
import ErrorBanner from '../common/ErrorBanner.vue'
import LoadingState from '../common/LoadingState.vue'
import RateChart from '../common/RateChart.vue'
import HistoryToolbar from '../HistoryToolbar.vue'

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
		},
	},
	methods: {
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
					? 'Collecting metrics — charts populate after ~60 seconds of sidecar polling.'
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
