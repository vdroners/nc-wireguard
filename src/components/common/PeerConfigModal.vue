<template>
	<div v-if="open" class="nc-wg-modal-backdrop" @click.self="close">
		<div class="nc-wg-modal card">
			<header class="nc-wg-modal__header">
				<h3>{{ clientName }} — WireGuard config</h3>
				<button type="button" class="nc-wg-btn" @click="close">Close</button>
			</header>
			<LoadingState v-if="loading" message="Loading configuration…" />
			<ErrorBanner v-if="error" :message="error" title="Config error" :dismissible="false" />
			<div v-if="configText && !loading" class="nc-wg-modal__body">
				<div v-if="qrDataUrl" class="nc-wg-modal__qr">
					<img :src="qrDataUrl" alt="WireGuard QR code" width="200" height="200">
				</div>
				<pre class="nc-wg-config-pre">{{ configText }}</pre>
				<button type="button" class="nc-wg-btn nc-wg-btn--primary" @click="copyConfig">Copy</button>
			</div>
		</div>
	</div>
</template>

<script>
import QRCode from 'qrcode'
import { fetchPeerConfig, extractApiError } from '../../services/dashboard-api.js'
import ErrorBanner from './ErrorBanner.vue'
import LoadingState from './LoadingState.vue'

export default {
	name: 'PeerConfigModal',
	components: { ErrorBanner, LoadingState },
	props: {
		open: { type: Boolean, default: false },
		clientId: { type: Number, default: null },
		clientName: { type: String, default: '' },
	},
	data() {
		return {
			loading: false,
			error: '',
			configText: '',
			qrDataUrl: '',
		}
	},
	watch: {
		open(val) {
			if (val && this.clientId) this.load()
		},
		clientId(val) {
			if (this.open && val) this.load()
		},
	},
	methods: {
		close() {
			this.$emit('close')
		},
		async load() {
			this.loading = true
			this.error = ''
			this.configText = ''
			this.qrDataUrl = ''
			try {
				const data = await fetchPeerConfig(this.clientId)
				const text = typeof data === 'string'
					? data
					: (data?.configuration || data?.config || JSON.stringify(data, null, 2))
				this.configText = text
				if (text && text.includes('[Interface]')) {
					this.qrDataUrl = await QRCode.toDataURL(text, { width: 200, margin: 1 })
				}
			} catch (e) {
				this.error = extractApiError(e)
			} finally {
				this.loading = false
			}
		},
		async copyConfig() {
			try {
				await navigator.clipboard.writeText(this.configText)
			} catch (_) {
				// ignore
			}
		},
	},
}
</script>
