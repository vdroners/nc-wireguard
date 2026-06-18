<template>
	<div class="nc-wg-toolbar">
		<label v-if="timeLabel">{{ timeLabel }}</label>
		<select :value="String(timeValue)" @change="onTimeChange">
			<option v-for="opt in timeOptions" :key="opt.value" :value="String(opt.value)">
				{{ opt.label }}
			</option>
		</select>
		<template v-if="showClient">
			<label>Client:</label>
			<select :value="clientId" @change="onClientChange">
				<option value="">All</option>
				<option v-for="c in clients" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
			</select>
		</template>
		<button type="button" class="nc-wg-btn nc-wg-btn--primary" @click="$emit('refresh')">
			Refresh
		</button>
	</div>
</template>

<script>
export default {
	name: 'HistoryToolbar',
	props: {
		timeLabel: { type: String, default: 'Time range:' },
		timeValue: { type: [Number, String], required: true },
		timeOptions: { type: Array, required: true },
		clientId: { type: String, default: '' },
		clients: { type: Array, default: () => [] },
		showClient: { type: Boolean, default: true },
	},
	methods: {
		onTimeChange(e) {
			const raw = e.target.value
			const num = Number(raw)
			this.$emit('time-change', Number.isNaN(num) ? raw : num)
		},
		onClientChange(e) {
			this.$emit('client-change', e.target.value)
		},
	},
}
</script>
