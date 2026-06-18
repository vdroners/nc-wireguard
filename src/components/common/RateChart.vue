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
		title: { type: String, default: '' },
		showTitle: { type: Boolean, default: true },
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
			const textColor = getComputedStyle(document.documentElement).getPropertyValue('--color-main-text').trim() || '#e5e5e5'
			const mutedColor = getComputedStyle(document.documentElement).getPropertyValue('--color-text-maxcontrast').trim() || '#737373'
			const gridColor = getComputedStyle(document.documentElement).getPropertyValue('--color-border').trim() || '#333'
			this.chart = new Chart(ctx, {
				type: 'line',
				data: { datasets: ds },
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: {
							display: this.showLegend,
							labels: { color: mutedColor, boxWidth: 12, font: { size: 11 } },
						},
						title: {
							display: this.showTitle && !!this.title,
							text: this.title,
							color: textColor,
							font: { size: 13 },
						},
					},
					scales: {
						x: {
							type: 'time',
							ticks: { color: mutedColor, maxTicksLimit: 10 },
							grid: { color: gridColor },
						},
						y: {
							ticks: { color: mutedColor, callback: v => this.yCallback(v) },
							grid: { color: gridColor },
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
