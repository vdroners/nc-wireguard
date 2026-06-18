<template>
	<div v-if="message" class="nc-wg-error-banner" role="alert">
		<strong>{{ title }}</strong>
		<span>{{ message }}</span>
		<button v-if="dismissible" type="button" class="nc-wg-error-banner__dismiss" @click="$emit('dismiss')">×</button>
	</div>
</template>

<script>
export default {
	name: 'ErrorBanner',
	props: {
		message: { type: String, default: '' },
		title: { type: String, default: 'Error' },
		dismissible: { type: Boolean, default: true },
	},
}
</script>
