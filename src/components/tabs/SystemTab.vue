<template>
	<div class="nc-wg-tab">
		<ErrorBanner v-if="error" :message="error" @dismiss="error = ''" />
		<div class="nc-wg-toolbar">
			<label>Time range:</label>
			<select v-model="hours" @change="load">
				<option :value="1">1 hour</option>
				<option :value="6">6 hours</option>
				<option :value="24">24 hours</option>
				<option :value="168">7 days</option>
			</select>
			<button type="button" class="nc-wg-btn nc-wg-btn--primary" @click="load">Refresh</button>
		</div>
		<p v-if="coldStartHint" class="nc-wg-hint">{{ coldStartHint }}</p>
		<LoadingState v-if="loading" message="Loading system metrics…" />
		<div class="card p-4 mb-4"><RateChart title="CPU Usage" :datasets="cpuDatasets" y-format="percent" :y-max="100" :show-legend="false" filled /></div>
		<div class="card p-4 mb-4"><RateChart title="Memory Usage" :datasets="memDatasets" y-format="percent" :y-max="100" :show-legend="false" filled /></div>
		<div class="card p-4"><RateChart title="Network I/O" :datasets="netDatasets" /></div>
	</div>
</template>

<script>
import { fetchSystem, extractApiError } from '../../services/dashboard-api.js'
import { buildSystemNetRates } from '../../utils/bandwidth-rates.js'
import ErrorBanner from '../common/ErrorBanner.vue'
import LoadingState from '../common/LoadingState.vue'
import RateChart from '../common/RateChart.vue'

export default {
	name: 'SystemTab',
	components: { ErrorBanner, LoadingState, RateChart },
	props: {
		active: { type: Boolean, default: false },
	},
	data() {
		return {
			hours: 24,
			cpuDatasets: [],
			memDatasets: [],
			netDatasets: [],
			error: '',
			loading: false,
			loaded: false,
			coldStartHint: '',
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
				this.cpuDatasets = [{
					label: 'CPU',
					data: ts.map((t, i) => ({ x: t, y: data[i].cpu_percent })),
					borderColor: '#dc2626',
					backgroundColor: '#dc262633',
				}]
				this.memDatasets = [{
					label: 'Memory',
					data: ts.map((t, i) => ({ x: t, y: data[i].mem_percent })),
					borderColor: '#2563eb',
					backgroundColor: '#2563eb33',
				}]
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
