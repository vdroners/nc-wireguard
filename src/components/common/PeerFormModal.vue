<template>
	<div v-if="open" class="nc-wg-modal-backdrop" @click.self="close">
		<div class="nc-wg-modal card nc-wg-modal--form">
			<header class="nc-wg-modal__header">
				<h3>{{ isEdit ? 'Edit peer' : 'New peer' }}</h3>
				<button type="button" class="nc-wg-btn" @click="close">Close</button>
			</header>
			<form class="nc-wg-modal__body" @submit.prevent="submit">
				<label class="nc-wg-field">
					<span>Name</span>
					<input v-model="form.name" type="text" required maxlength="128" class="nc-wg-input">
				</label>
				<label class="nc-wg-field">
					<span>Expires at (optional ISO)</span>
					<input v-model="form.expiresAt" type="text" placeholder="2027-01-01T00:00:00.000Z or empty" class="nc-wg-input">
				</label>
				<div class="nc-wg-presets">
					<span class="nc-wg-presets__label">Presets</span>
					<button type="button" class="nc-wg-btn" @click="applyPreset('field')">Field / site GCS</button>
					<button type="button" class="nc-wg-btn" @click="applyPreset('admin')">Admin full tunnel</button>
				</div>
				<label class="nc-wg-field">
					<span>AllowedIPs (CSV)</span>
					<input v-model="form.allowedIps" type="text" class="nc-wg-input" placeholder="10.0.0.0/24,10.8.0.0/24">
				</label>
				<label class="nc-wg-field">
					<span>DNS (CSV, optional)</span>
					<input v-model="form.dns" type="text" class="nc-wg-input" placeholder="1.1.1.1">
				</label>
				<label class="nc-wg-field">
					<span>MTU</span>
					<input v-model.number="form.mtu" type="number" min="1280" max="9000" class="nc-wg-input">
				</label>
				<label class="nc-wg-field">
					<span>PersistentKeepalive</span>
					<input v-model.number="form.persistentKeepalive" type="number" min="0" max="65535" class="nc-wg-input">
				</label>
				<ErrorBanner v-if="error" :message="error" title="Save failed" :dismissible="false" />
				<div class="nc-wg-modal__actions">
					<button type="submit" class="nc-wg-btn nc-wg-btn--primary" :disabled="saving">
						{{ saving ? 'Saving…' : (isEdit ? 'Save' : 'Create') }}
					</button>
				</div>
			</form>
		</div>
	</div>
</template>

<script>
import {
	createPeer,
	updatePeer,
	extractApiError,
	PRESET_FIELD,
	PRESET_ADMIN,
} from '../../services/dashboard-api.js'
import ErrorBanner from './ErrorBanner.vue'

function listToCsv(val) {
	if (Array.isArray(val)) return val.join(',')
	if (val == null) return ''
	return String(val)
}

export default {
	name: 'PeerFormModal',
	components: { ErrorBanner },
	props: {
		open: { type: Boolean, default: false },
		peer: { type: Object, default: null },
	},
	emits: ['close', 'saved'],
	data() {
		return {
			form: {
				name: '',
				expiresAt: '',
				allowedIps: PRESET_FIELD.allowedIps,
				dns: '',
				mtu: 1420,
				persistentKeepalive: 25,
			},
			saving: false,
			error: '',
		}
	},
	computed: {
		isEdit() {
			return !!(this.peer && this.peer.id)
		},
	},
	watch: {
		open(val) {
			if (val) this.resetFromPeer()
		},
	},
	methods: {
		close() {
			this.$emit('close')
		},
		applyPreset(kind) {
			const p = kind === 'admin' ? PRESET_ADMIN : PRESET_FIELD
			this.form.allowedIps = p.allowedIps
			this.form.dns = p.dns
			this.form.mtu = p.mtu
			this.form.persistentKeepalive = p.persistentKeepalive
		},
		resetFromPeer() {
			this.error = ''
			if (this.peer && this.peer.id) {
				this.form = {
					name: this.peer.name || '',
					expiresAt: this.peer.expiresAt || '',
					allowedIps: listToCsv(this.peer.allowedIps) || PRESET_FIELD.allowedIps,
					dns: listToCsv(this.peer.dns),
					mtu: this.peer.mtu ?? 1420,
					persistentKeepalive: this.peer.persistentKeepalive ?? 25,
				}
			} else {
				this.form = {
					name: '',
					expiresAt: '',
					allowedIps: PRESET_FIELD.allowedIps,
					dns: '',
					mtu: 1420,
					persistentKeepalive: 25,
				}
			}
		},
		payload() {
			const body = {
				name: this.form.name.trim(),
				allowedIps: this.form.allowedIps,
				dns: this.form.dns || null,
				mtu: Number(this.form.mtu) || 1420,
				persistentKeepalive: Number(this.form.persistentKeepalive) || 0,
				// wg-easy create requires the key; null = no expiry
				expiresAt: this.form.expiresAt.trim() === '' ? null : this.form.expiresAt.trim(),
			}
			return body
		},
		async submit() {
			this.saving = true
			this.error = ''
			try {
				const body = this.payload()
				let result
				if (this.isEdit) {
					result = await updatePeer(this.peer.id, body)
				} else {
					result = await createPeer(body)
				}
				this.$emit('saved', result)
				this.close()
			} catch (e) {
				this.error = extractApiError(e)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.nc-wg-modal--form { max-width: 32rem; }
.nc-wg-field { display: flex; flex-direction: column; gap: 0.25rem; margin-bottom: 0.75rem; }
.nc-wg-input { padding: 0.4rem 0.5rem; }
.nc-wg-presets { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-bottom: 0.75rem; }
.nc-wg-presets__label { font-size: 0.85rem; opacity: 0.8; }
</style>
