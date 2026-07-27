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
						<button type="button" class="nc-wg-btn" @click="downloadConf">Download .conf</button>
						<button type="button" class="nc-wg-btn" :disabled="otlBusy" @click="generateOtl">
							{{ otlBusy ? 'Generating…' : 'One-time link' }}
						</button>
						<button type="button" class="nc-wg-btn" @click="$emit('edit')">Edit peer</button>
						<a
							v-if="wgEasyUrl"
							class="nc-wg-admin-link"
							:href="wgEasyUrl"
							target="_blank"
							rel="noopener">
							Engine admin →
						</a>
					</div>
					<div v-if="otlUrl" class="nc-wg-otl">
						<label class="muted">Admin redeem URL (NC login required — not for field users)</label>
						<input class="nc-wg-input" type="text" readonly :value="otlUrl">
						<button type="button" class="nc-wg-btn nc-wg-btn--sm" @click="copyOtl">Copy link</button>
						<p class="muted nc-wg-otl__hint">
							For field install, use Download .conf or the QR above. This NC link only works while you are logged in as admin (~5 min TTL).
						</p>
					</div>
				</div>
			</div>
			<div v-if="toast" class="nc-wg-toast">{{ toast }}</div>
		</div>
	</div>
</template>

<script>
import QRCode from 'qrcode'
import {
	fetchPeerConfig,
	generatePeerOtl,
	extractApiError,
} from '../../services/dashboard-api.js'
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
	emits: ['close', 'edit'],
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
			otlUrl: '',
			otlBusy: false,
		}
	},
	watch: {
		open(val) {
			if (val && this.clientId) this.load()
			if (!val) {
				this.otlUrl = ''
			}
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
			this.otlUrl = ''
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
		downloadConf() {
			const blob = new Blob([this.configText], { type: 'text/plain' })
			const url = URL.createObjectURL(blob)
			const a = document.createElement('a')
			const safe = (this.clientName || 'peer').replace(/[^\w.-]+/g, '_')
			a.href = url
			a.download = `${safe}.conf`
			document.body.appendChild(a)
			a.click()
			a.remove()
			URL.revokeObjectURL(url)
		},
		async generateOtl() {
			this.otlBusy = true
			try {
				const data = await generatePeerOtl(this.clientId)
				this.otlUrl = data.redeemUrl || data.oneTimeLink || ''
				if (!this.otlUrl && data.redeemPath) {
					this.otlUrl = data.redeemPath
				}
				if (this.otlUrl) {
					this.showToast('Admin OTL ready — use .conf/QR for field')
				} else {
					this.showToast('OTL generated but no URL returned')
				}
			} catch (e) {
				this.error = extractApiError(e)
			} finally {
				this.otlBusy = false
			}
		},
		async copyOtl() {
			try {
				await navigator.clipboard.writeText(this.otlUrl)
				this.showToast('Link copied')
			} catch (_) {
				this.showToast('Copy failed')
			}
		},
	},
}
</script>

<style scoped>
.nc-wg-otl {
	margin-top: 0.75rem;
	display: flex;
	flex-direction: column;
	gap: 0.35rem;
}
.nc-wg-otl__hint {
	margin: 0;
	font-size: 0.85rem;
}
.nc-wg-input {
	padding: 0.4rem 0.5rem;
	width: 100%;
}
</style>
