<template>
	<div class="nc-wg-tab">
		<ErrorBanner v-if="error" :message="error" @dismiss="error = ''" />
		<div class="nc-wg-toolbar">
			<label>Days:</label>
			<select v-model="days" @change="load">
				<option :value="1">1 day</option>
				<option :value="7">7 days</option>
				<option :value="30">30 days</option>
			</select>
			<label>Client:</label>
			<select v-model="clientId" @change="load">
				<option value="">All</option>
				<option v-for="c in clients" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
			</select>
			<button type="button" class="nc-wg-btn nc-wg-btn--primary" @click="load">Refresh</button>
		</div>
		<LoadingState v-if="loading" message="Loading connections…" />
		<div class="card nc-wg-table-wrap">
			<div class="nc-wg-table-scroll">
				<table class="nc-wg-table">
					<thead>
						<tr><th>Time</th><th>Client</th><th>Event</th><th>Endpoint</th><th>Location</th></tr>
					</thead>
					<tbody>
						<tr v-for="(row, i) in rows" :key="i">
							<td class="muted">{{ fmtTime(row.ts) }}</td>
							<td>{{ row.name }}</td>
							<td><span :class="row.event === 'connected' ? 'badge-on' : 'badge-off'">{{ row.event }}</span></td>
							<td class="mono">{{ row.endpoint || '—' }}</td>
							<td>{{ formatGeo(row) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div v-if="!loading && !rows.length" class="nc-wg-empty-block">
				No connection events recorded yet. Data appears after clients connect/disconnect.
			</div>
		</div>
	</div>
</template>

<script>
import { fmtTime } from '../../utils/format.js'
import { fetchConnections, extractApiError } from '../../services/dashboard-api.js'
import ErrorBanner from '../common/ErrorBanner.vue'
import LoadingState from '../common/LoadingState.vue'

export default {
	name: 'ConnectionsTab',
	components: { ErrorBanner, LoadingState },
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
		}
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
			return `${g.city || ''}, ${g.country || ''}`.replace(/^, /, '').trim() || '—'
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
