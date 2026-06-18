<template>
	<div class="nc-wg-tab">
		<ErrorBanner v-if="error" :message="error" @dismiss="error = ''" />
		<HistoryToolbar
			time-label="Days:"
			:time-value="days"
			:time-options="timeOptions"
			:client-id="clientId"
			:clients="clients"
			@time-change="days = $event; load()"
			@client-change="clientId = $event; load()"
			@refresh="load" />
		<LoadingState v-if="loading" message="Loading connections…" />
		<div class="card nc-wg-table-wrap">
			<div class="nc-wg-table-scroll">
				<table class="nc-wg-table">
					<thead>
						<tr><th>Time</th><th>Client</th><th>Event</th><th>Endpoint</th><th>Location</th></tr>
					</thead>
					<tbody>
						<tr v-for="(row, i) in displayRows" :key="i">
							<td class="muted">{{ fmtTime(row.ts) }}</td>
							<td>{{ row.name }}</td>
							<td><span :class="row.event === 'connected' ? 'badge-on' : 'badge-off'">{{ row.event }}</span></td>
							<td class="mono nc-wg-truncate" :title="row.endpoint || ''">{{ row.endpoint || '—' }}</td>
							<td>{{ formatGeo(row) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div v-if="!loading && !displayRows.length" class="nc-wg-empty-block">
				No connection events recorded yet. Data appears after clients connect/disconnect.
			</div>
			<p v-if="truncated" class="nc-wg-hint nc-wg-hint--inline">
				Showing first 100 events. Narrow the client filter to see more.
			</p>
		</div>
	</div>
</template>

<script>
import { fmtTime } from '../../utils/format.js'
import { countryFlag } from '../../utils/peer.js'
import { fetchConnections, extractApiError } from '../../services/dashboard-api.js'
import ErrorBanner from '../common/ErrorBanner.vue'
import LoadingState from '../common/LoadingState.vue'
import HistoryToolbar from '../HistoryToolbar.vue'

const MAX_ROWS = 100

export default {
	name: 'ConnectionsTab',
	components: { ErrorBanner, LoadingState, HistoryToolbar },
	props: {
		active: { type: Boolean, default: false },
		clients: { type: Array, default: () => [] },
	},
	data() {
		return {
			days: 7,
			clientId: '',
			rows: [],
			error: '',
			loading: false,
			loaded: false,
			timeOptions: [
				{ value: 1, label: '1 day' },
				{ value: 7, label: '7 days' },
				{ value: 30, label: '30 days' },
			],
		}
	},
	computed: {
		displayRows() {
			return this.rows.slice(0, MAX_ROWS)
		},
		truncated() {
			return this.rows.length > MAX_ROWS
		},
	},
	watch: {
		active(val) {
			if (val && !this.loaded) this.load()
		},
	},
	methods: {
		fmtTime,
		formatGeo(row) {
			const g = row.geo
			if (!g) return '—'
			const flag = countryFlag(g.country_code)
			const place = `${g.city || ''}, ${g.country || ''}`.replace(/^, /, '').trim()
			return flag ? `${flag} ${place}` : (place || '—')
		},
		async load() {
			this.loading = true
			this.error = ''
			try {
				const params = { days: this.days }
				if (this.clientId) params.client_id = this.clientId
				this.rows = await fetchConnections(params)
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
