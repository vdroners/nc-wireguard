<template>
	<div class="nc-wg-loading" role="status">
		<span class="nc-wg-loading__spinner" aria-hidden="true" />
		<span>{{ message }}</span>
	</div>
</template>

<script>
export default {
	name: 'LoadingState',
	props: {
		message: { type: String, default: 'Loading…' },
	},
}
</script>
