<template>
	<NcNoteCard
		v-if="message"
		type="error"
		:heading="title"
		class="nc-wg-error-note"
		role="alert">
		<div class="nc-wg-error-note__body">
			<span class="nc-wg-error-note__text">{{ message }}</span>
			<button
				v-if="dismissible"
				type="button"
				class="nc-wg-error-note__dismiss"
				aria-label="Dismiss"
				@click="$emit('dismiss')">
				×
			</button>
		</div>
	</NcNoteCard>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'ErrorBanner',
	components: { NcNoteCard },
	props: {
		message: { type: String, default: '' },
		title: { type: String, default: 'Error' },
		dismissible: { type: Boolean, default: true },
	},
}
</script>

<style scoped>
.nc-wg-error-note {
	margin-bottom: 1rem;
}

.nc-wg-error-note__body {
	display: flex;
	align-items: flex-start;
	gap: 0.75rem;
}

.nc-wg-error-note__text {
	flex: 1 1 auto;
	min-width: 0;
}

.nc-wg-error-note__dismiss {
	flex: 0 0 auto;
	background: transparent;
	border: none;
	color: inherit;
	cursor: pointer;
	font-size: 1.25rem;
	line-height: 1;
	min-width: 44px;
	min-height: 44px;
	padding: 0;
	opacity: 0.85;
}

.nc-wg-error-note__dismiss:hover {
	opacity: 1;
}
</style>
