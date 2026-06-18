<template>
	<div class="nc-wg-tab">
		<ErrorBanner v-if="error" :message="error" @dismiss="error = ''" />
		<HistoryToolbar
			:time-value="hours"
			:time-options="timeOptions"
			:client-id="clientId"
			:clients="clients"
			@time-change="hours = $event; load()"
			@client-change="clientId = $event; load()"
			@refresh="load" />
		<p v-if="coldStartHint" class="nc-wg-hint">{{ coldStartHint }}</p>
		<LoadingState v-if="loading" message="Loading bandwidth…" />
		<div class="nc-wg-chart-grid">
			<div class="card p-4">
				<h3 class="nc-wg-chart-title">Download Rate (Rx)</h3>
				<RateChart :datasets="rxDatasets" :show-title="false" />
			</div>
			<div class="card p-4">
				<h3 class="nc-wg-chart-title">Upload Rate (Tx)</h3>
				<RateChart :datasets="txDatasets" :show-title="false" />
			</div>
		</div>
	</div>
</template>

<script>
import { fetchBandwidth, extractApiError } from '../../services/dashboard-api.js'
import { buildBandwidthRateDatasets } from '../../utils/bandwidth-rates.js'
import ErrorBanner from '../common/ErrorBanner.vue'
import LoadingState from '../common/LoadingState.vue'
import RateChart from '../common/RateChart.vue'
import HistoryToolbar from '../HistoryToolbar.vue'

export default {
	name: 'BandwidthTab',
	components: { ErrorBanner, LoadingState, RateChart, HistoryToolbar },
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
