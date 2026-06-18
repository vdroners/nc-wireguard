<template>
	<div class="nc-wg-tab">
		<ErrorBanner v-if="error" :message="error" @dismiss="error = ''" />
		<LoadingState v-if="loading" message="Loading map…" />
		<div class="card p-4 mb-4">
			<div ref="mapEl" class="nc-wg-map" />
		</div>
		<div class="card nc-wg-table-wrap">
			<div class="nc-wg-table-scroll">
				<table class="nc-wg-table">
					<thead>
						<tr><th>IP</th><th>Country</th><th>City</th><th>ISP</th><th>Coordinates</th></tr>
					</thead>
					<tbody>
						<tr v-for="g in geoRows" :key="g.ip">
							<td class="mono">{{ g.ip }}</td>
							<td>{{ g.country || '—' }} {{ g.country_code ? '(' + g.country_code + ')' : '' }}</td>
							<td>{{ g.city || '—' }}</td>
							<td class="muted">{{ g.isp || '—' }}</td>
							<td class="mono muted">{{ formatCoords(g) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</template>

<script>
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { fetchGeoip, extractApiError } from '../../services/dashboard-api.js'
import ErrorBanner from '../common/ErrorBanner.vue'
import LoadingState from '../common/LoadingState.vue'

export default {
	name: 'MapTab',
	components: { ErrorBanner, LoadingState },
	props: {
		active: { type: Boolean, default: false },
	},
	data() {
		return {
			map: null,
			markers: [],
			geoRows: [],
			error: '',
			loading: false,
			loaded: false,
		}
	},
	watch: {
		active(val) {
			if (val && !this.loaded) this.load()
			if (val && this.map) {
				this.$nextTick(() => this.map.invalidateSize())
			}
		},
	},
	beforeDestroy() {
		this.destroyMap()
	},
	methods: {
		formatCoords(g) {
			if (g.lat == null || g.lon == null) return '—'
			return `${g.lat.toFixed(2)}, ${g.lon.toFixed(2)}`
		},
		destroyMap() {
			if (this.map) {
				this.map.remove()
				this.map = null
			}
			this.markers = []
		},
		async load() {
			this.loading = true
			this.error = ''
			try {
				const data = await fetchGeoip()
				this.geoRows = data
				this.loaded = true
				await this.$nextTick()
				this.renderMap(data)
			} catch (e) {
				this.error = extractApiError(e)
			} finally {
				this.loading = false
			}
		},
		renderMap(data) {
			if (!this.$refs.mapEl) return
			if (!this.map) {
				this.map = L.map(this.$refs.mapEl, { zoomControl: true }).setView([20, 0], 2)
				L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
					attribution: '&copy; OSM &copy; CARTO',
					maxZoom: 18,
				}).addTo(this.map)
			}
			this.markers.forEach(m => this.map.removeLayer(m))
			this.markers = []
			for (const g of data) {
				if (g.lat && g.lon) {
					const m = L.circleMarker([g.lat, g.lon], {
						radius: 8,
						fillColor: '#dc2626',
						color: '#fff',
						weight: 1,
						fillOpacity: 0.8,
					})
						.bindPopup(`<b>${g.ip}</b><br>${g.city || ''}, ${g.country || ''}<br>${g.isp || ''}`)
						.addTo(this.map)
					this.markers.push(m)
				}
			}
			const bounds = data.filter(g => g.lat && g.lon).map(g => [g.lat, g.lon])
			if (bounds.length) {
				this.map.fitBounds(bounds, { padding: [40, 40], maxZoom: 6 })
			}
			setTimeout(() => this.map?.invalidateSize(), 200)
		},
	},
}
</script>
