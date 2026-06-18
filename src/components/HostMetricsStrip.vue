<template>
	<div class="nc-wg-host-strip card" :class="{ 'nc-wg-host-strip--pulse': refreshing }">
		<div v-for="m in metrics" :key="m.key" class="nc-wg-host-strip__item">
			<div class="nc-wg-host-strip__label">{{ m.label }}</div>
			<div class="nc-wg-host-strip__bar-wrap">
				<div class="nc-wg-host-strip__bar" :style="{ width: m.value + '%', background: m.color }" />
			</div>
			<div class="nc-wg-host-strip__value">{{ m.value.toFixed(1) }}%</div>
		</div>
	</div>
</template>

<script>
export default {
	name: 'HostMetricsStrip',
	props: {
		cpu: { type: Number, default: 0 },
		mem: { type: Number, default: 0 },
		disk: { type: Number, default: 0 },
		refreshing: { type: Boolean, default: false },
	},
	computed: {
		metrics() {
			return [
				{ key: 'cpu', label: 'CPU', value: this.cpu || 0, color: '#dc2626' },
				{ key: 'mem', label: 'Memory', value: this.mem || 0, color: '#2563eb' },
				{ key: 'disk', label: 'Disk', value: this.disk || 0, color: '#d97706' },
			]
		},
	},
}
</script>
