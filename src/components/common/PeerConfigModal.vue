<template>
	<div v-if="open" class="nc-wg-modal-backdrop" @click.self="close">
		<div class="nc-wg-modal card">
			<header class="nc-wg-modal__header">
				<h3>{{ clientName }} — WireGuard config</h3>
				<button type="button" class="nc-wg-btn" @click="close">Close</button>
			</header>
			<LoadingState v-if="loading" message="Loading configuration…" />
			<ErrorBanner v-if="error" :message="error" title="Config error" :dismissible="false" />
			<div v-if="configText && !loading" class="nc-wg-modal__body" :class="{ 'nc-wg-modal__body--wide': wideLayout }">
				<div v-if="qrDataUrl" class="nc-wg-modal__qr">
					<img :src="qrDataUrl" alt="WireGuard QR code" width="200" height="200">
				</div>
				<div class="nc-wg-modal__config-col">
					<pre class="nc-wg-config-pre">{{ configText }}</pre>
					<div class="nc-wg-modal__actions">
						<button type="button" class="nc-wg-btn nc-wg-btn--primary" @click="copyConfig">Copy</button>
						<a
							v-if="wgEasyUrl"
							class="nc-wg-admin-link"
							:href="wgEasyUrl"
							target="_blank"
							rel="noopener">
							Edit in wg-easy →
						</a>
					</div>
				</div>
			</div>
			<div v-if="toast" class="nc-wg-toast">{{ toast }}</div>
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
		wgEasyUrl: { type: String, default: '' },
	},
	data() {
		return {
			loading: false,
			error: '',
			configText: '',
			qrDataUrl: '',
			toast: '',
			toastTimer: null,
			wideLayout: false,
			resizeHandler: null,
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
	mounted() {
		this.updateWideLayout()
		this.resizeHandler = () => this.updateWideLayout()
		window.addEventListener('resize', this.resizeHandler)
	},
	beforeDestroy() {
		if (this.resizeHandler) {
			window.removeEventListener('resize', this.resizeHandler)
		}
		if (this.toastTimer) clearTimeout(this.toastTimer)
	},
	methods: {
		updateWideLayout() {
			this.wideLayout = typeof window !== 'undefined' && window.innerWidth >= 640
		},
		close() {
			this.$emit('close')
		},
		showToast(msg) {
			this.toast = msg
			if (this.toastTimer) clearTimeout(this.toastTimer)
			this.toastTimer = setTimeout(() => { this.toast = '' }, 2000)
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
				this.showToast('Copied!')
			} catch (_) {
				this.showToast('Copy failed')
			}
		},
	},
}
</script>
