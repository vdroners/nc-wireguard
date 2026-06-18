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
			<label>Client:</label>
			<select v-model="clientId" @change="load">
				<option value="">All</option>
				<option v-for="c in clients" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
			</select>
			<button type="button" class="nc-wg-btn nc-wg-btn--primary" @click="load">Refresh</button>
		</div>
		<p v-if="coldStartHint" class="nc-wg-hint">{{ coldStartHint }}</p>
		<LoadingState v-if="loading" message="Loading bandwidth…" />
		<div class="card p-4 mb-4"><RateChart title="Download Rate (Rx)" :datasets="rxDatasets" /></div>
		<div class="card p-4"><RateChart title="Upload Rate (Tx)" :datasets="txDatasets" /></div>
	</div>
</template>

<script>
import { fetchBandwidth, extractApiError } from '../../services/dashboard-api.js'
import { buildBandwidthRateDatasets } from '../../utils/bandwidth-rates.js'
import ErrorBanner from '../common/ErrorBanner.vue'
import LoadingState from '../common/LoadingState.vue'
import RateChart from '../common/RateChart.vue'

export default {
	name: 'BandwidthTab',
	components: { ErrorBanner, LoadingState, RateChart },
	props: {
		active: { type: Boolean, default: false },
		clients: { type: Array, default: () => [] },
	},
	data() {
		return {
			hours: 24,
			clientId: '',
			rxDatasets: [],
			txDatasets: [],
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
				const params = { hours: this.hours }
				if (this.clientId) params.client_id = this.clientId
				const data = await fetchBandwidth(params)
				const { rxDatasets, txDatasets, sampleCount } = buildBandwidthRateDatasets(data)
				this.rxDatasets = rxDatasets
				this.txDatasets = txDatasets
				this.coldStartHint = sampleCount < 2
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
