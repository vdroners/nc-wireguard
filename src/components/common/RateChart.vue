<template>
	<div class="nc-wg-chart-wrap">
		<canvas ref="canvas" />
	</div>
</template>

<script>
import {
	Chart,
	LineController,
	LineElement,
	PointElement,
	LinearScale,
	TimeScale,
	Title,
	Tooltip,
	Legend,
	Filler,
} from 'chart.js'
import 'chartjs-adapter-date-fns'
import { fmtBytes } from '../../utils/format.js'

Chart.register(LineController, LineElement, PointElement, LinearScale, TimeScale, Title, Tooltip, Legend, Filler)

export default {
	name: 'RateChart',
	props: {
		title: { type: String, required: true },
		datasets: { type: Array, default: () => [] },
		yMax: { type: Number, default: null },
		yFormat: { type: String, default: 'bytes' },
		showLegend: { type: Boolean, default: true },
		filled: { type: Boolean, default: false },
	},
	data() {
		return { chart: null }
	},
	watch: {
		datasets: {
			deep: true,
			handler() {
				this.renderChart()
			},
		},
	},
	mounted() {
		this.renderChart()
	},
	beforeDestroy() {
		if (this.chart) {
			this.chart.destroy()
			this.chart = null
		}
	},
	methods: {
		yCallback(v) {
			if (this.yFormat === 'bytes') return fmtBytes(v) + '/s'
			return v + '%'
		},
		renderChart() {
			const ctx = this.$refs.canvas?.getContext('2d')
			if (!ctx) return
			if (this.chart) {
				this.chart.destroy()
			}
			const ds = this.datasets.map(d => ({
				...d,
				fill: this.filled,
				tension: 0.2,
				pointRadius: 0,
				borderWidth: 1.5,
			}))
			this.chart = new Chart(ctx, {
				type: 'line',
				data: { datasets: ds },
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: {
							display: this.showLegend,
							labels: { color: '#a3a3a3', boxWidth: 12, font: { size: 11 } },
						},
						title: {
							display: true,
							text: this.title,
							color: '#e5e5e5',
							font: { size: 13 },
						},
					},
					scales: {
						x: {
							type: 'time',
							ticks: { color: '#737373', maxTicksLimit: 10 },
							grid: { color: '#333' },
						},
						y: {
							ticks: { color: '#737373', callback: v => this.yCallback(v) },
							grid: { color: '#333' },
							beginAtZero: true,
							max: this.yMax ?? undefined,
						},
					},
					interaction: { mode: 'index', intersect: false },
				},
			})
		},
	},
}
</script>
