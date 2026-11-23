<template>
	<div>
		<p v-if="loading" class="muted">{{ t('nc_wireguard', 'Loading…') }}</p>
		<div v-else class="nc-wireguard-admin-panel">
			<NcSettingsSection
				:name="t('nc_wireguard', 'NC WireGuard')"
				:description="versionHint">
				<NcNoteCard v-if="error" type="error" :heading="t('nc_wireguard', 'Error')">
					{{ error }}
				</NcNoteCard>
				<NcNoteCard v-if="message" type="success" :heading="t('nc_wireguard', 'Saved')">
					{{ message }}
				</NcNoteCard>

				<!-- Health (read-only) -->
				<div class="nc-wg-admin-health" data-testid="health-card">
					<h3>{{ t('nc_wireguard', 'Metrics health') }}</h3>
					<p v-if="healthUnknown" class="muted">
						{{ t('nc_wireguard', 'Status: unknown') }}
						<span v-if="health.status_reason"> — {{ health.status_reason }}</span>
					</p>
					<template v-else>
						<p>
							{{ t('nc_wireguard', 'Last poll') }}:
							{{ health.last_poll_at || t('nc_wireguard', 'unknown') }}
						</p>
						<p>
							wg_easy_ok:
							{{ health.wg_easy_ok === null || health.wg_easy_ok === undefined
								? t('nc_wireguard', 'unknown')
								: (health.wg_easy_ok ? t('nc_wireguard', 'yes') : t('nc_wireguard', 'no')) }}
						</p>
						<p v-if="health.error_message" class="err">
							{{ t('nc_wireguard', 'Last error') }}: {{ health.error_message }}
						</p>
						<p data-testid="handshake-summary">
							{{ handshakeSummary }}
						</p>
						<NcNoteCard
							v-if="showHandshakeWarning"
							type="warning"
							:heading="t('nc_wireguard', 'Peers without handshake')">
							{{ handshakeSummary }}
						</NcNoteCard>
					</template>
					<div
						v-if="legacyKeys.length"
						class="nc-wg-admin-legacy"
						data-testid="legacy-keys">
						<p class="muted">
							{{ t('nc_wireguard', 'Legacy appconfig keys present') }}:
							<code v-for="(key, i) in legacyKeys" :key="key">{{ key }}<template v-if="i < legacyKeys.length - 1">, </template></code>
						</p>
						<p v-if="unusedLegacyKeys.length" class="muted">
							{{ t('nc_wireguard', 'Unused keys') }}
							(<code v-for="(key, i) in unusedLegacyKeys" :key="'u-'+key">{{ key }}<template v-if="i < unusedLegacyKeys.length - 1">, </template></code>):
							{{ t('nc_wireguard', 'unused; safe to remove with occ config:app:delete after review') }}
						</p>
						<p v-if="wgSyncLegacyKeys.length" class="muted">
							{{ t('nc_wireguard', 'wg-sync keys') }}
							(<code v-for="(key, i) in wgSyncLegacyKeys" :key="'s-'+key">{{ key }}<template v-if="i < wgSyncLegacyKeys.length - 1">, </template></code>):
							{{ t('nc_wireguard', 'still used by the optional lab/native wg-sync path — do not delete while that path is in use') }}
						</p>
					</div>
				</div>

				<!-- VPN endpoint host (wg-easy userconfig) -->
				<div class="nc-wg-admin-endpoint" data-testid="endpoint-card">
					<h3>{{ t('nc_wireguard', 'VPN endpoint') }}</h3>
					<p class="muted">
						{{ t('nc_wireguard', 'Changing this changes the Endpoint in every newly downloaded client config; existing peers must re-import their config. Server keys and peers are not changed.') }}
					</p>
					<NcNoteCard v-if="endpointError" type="error" :heading="t('nc_wireguard', 'Error')">
						{{ endpointError }}
					</NcNoteCard>
					<NcNoteCard v-if="endpointMessage" type="success" :heading="t('nc_wireguard', 'Saved')">
						{{ endpointMessage }}
					</NcNoteCard>
					<div data-testid="endpoint-host">
						<NcTextField
							v-model="endpointHost"
							type="text"
							autocomplete="off"
							:label="t('nc_wireguard', 'Endpoint host (hostname or IPv4)')" />
					</div>
					<p class="muted">
						{{ t('nc_wireguard', 'UDP port') }}:
						<span data-testid="endpoint-port">{{ endpointPortDisplay }}</span>
						({{ t('nc_wireguard', 'read-only') }})
					</p>
					<div class="nc-wg-admin-actions">
						<NcButton
							data-testid="endpoint-save"
							:disabled="endpointSaving"
							type="primary"
							@click="saveEndpoint">
							{{ t('nc_wireguard', 'Save endpoint host') }}
						</NcButton>
					</div>
				</div>

				<h3>{{ t('nc_wireguard', 'Dashboard') }}</h3>
				<NcCheckboxRadioSwitch v-model="settings.dashboard_enabled" type="switch">
					{{ t('nc_wireguard', 'Dashboard enabled') }}
				</NcCheckboxRadioSwitch>
				<NcTextField
					v-model="settings.wg_easy_admin_url"
					type="url"
					:label="t('nc_wireguard', 'Engine admin URL (optional break-glass deep link)')" />
				<NcCheckboxRadioSwitch v-model="settings.hide_wg_easy_admin_link" type="switch">
					{{ t('nc_wireguard', 'Hide engine admin link in dashboard (recommended)') }}
				</NcCheckboxRadioSwitch>

				<h3>{{ t('nc_wireguard', 'WireGuard engine API (poller + writes)') }}</h3>
				<NcTextField
					v-model="settings.wg_easy_api_url"
					type="url"
					:label="t('nc_wireguard', 'Engine API URL (internal Docker)')" />
				<NcTextField
					v-model="settings.wg_easy_username"
					type="text"
					autocomplete="off"
					:label="t('nc_wireguard', 'Engine username')" />
				<NcPasswordField
					v-model="wgEasyPassword"
					autocomplete="new-password"
					:label="enginePasswordLabel" />
				<p class="muted">{{ t('nc_wireguard', 'TOTP must stay off on the API service account.') }}</p>
				<NcTextField
					v-model.number="settings.poll_interval_seconds"
					type="number"
					:min="10"
					:max="300"
					:label="t('nc_wireguard', 'Poll interval (seconds)')" />
				<NcTextField
					v-model.number="settings.retention_days"
					type="number"
					:min="1"
					:max="365"
					:label="t('nc_wireguard', 'Retention (days)')" />
				<NcCheckboxRadioSwitch v-model="settings.geoip_enabled" type="switch">
					{{ t('nc_wireguard', 'GeoIP lookups on connect (off by default)') }}
				</NcCheckboxRadioSwitch>
				<p class="muted">
					{{ t('nc_wireguard', 'When enabled, peer public IPs may be sent to a GeoIP provider. ip-api.com free tier is HTTP-only and non-commercial; use a Pro API key or custom HTTPS endpoint for production.') }}
				</p>
				<label class="nc-wg-admin-select-label" for="nc-wg-geoip-provider">
					{{ t('nc_wireguard', 'GeoIP provider') }}
				</label>
				<select
					id="nc-wg-geoip-provider"
					v-model="settings.geoip_provider"
					class="nc-wg-input">
					<option value="ip_api">ip-api.com (default template)</option>
					<option value="custom">{{ t('nc_wireguard', 'Custom URL template') }}</option>
				</select>
				<NcPasswordField
					v-model="geoipApiKey"
					autocomplete="new-password"
					:label="geoipKeyLabel"
					:placeholder="t('nc_wireguard', 'Leave blank to keep existing')" />
				<NcTextField
					v-model="settings.geoip_custom_url"
					type="url"
					:label="t('nc_wireguard', 'Custom GeoIP URL (use {ip} and {fields} placeholders)')"
					placeholder="https://example.com/geoip/{ip}?fields={fields}" />

				<h3>{{ t('nc_wireguard', 'Metrics health watchdog') }}</h3>
				<p class="muted">
					{{ t('nc_wireguard', 'Background job logs stale polls and engine failures (see Nextcloud log).') }}
				</p>
				<NcCheckboxRadioSwitch v-model="settings.watchdog_enabled" type="switch">
					{{ t('nc_wireguard', 'Watchdog job enabled') }}
				</NcCheckboxRadioSwitch>
				<NcTextField
					v-model.number="settings.watchdog_interval_minutes"
					type="number"
					:min="1"
					:max="60"
					:label="t('nc_wireguard', 'Watchdog interval (minutes)')" />

				<div class="nc-wg-admin-actions">
					<NcButton :disabled="saving" type="primary" @click="save">
						{{ t('nc_wireguard', 'Save') }}
					</NcButton>
					<NcButton :disabled="testing" @click="test">
						{{ t('nc_wireguard', 'Test native health') }}
					</NcButton>
					<NcButton :disabled="testingWgEasy" @click="testWgEasy">
						{{ t('nc_wireguard', 'Test engine') }}
					</NcButton>
				</div>

				<pre v-if="testResult" class="nc-wg-test-result">{{ t('nc_wireguard', 'Native health') }}: {{ formatJson(testResult) }}</pre>
				<pre v-if="wgEasyTestResult" class="nc-wg-test-result">{{ t('nc_wireguard', 'Engine') }}: {{ formatJson(wgEasyTestResult) }}</pre>

				<p>
					<a :href="dashboardHref">{{ t('nc_wireguard', 'Open dashboard') }}</a>
				</p>
				<p class="muted nc-wg-legal">
					{{ t('nc_wireguard', 'Not affiliated with or endorsed by WireGuard or wg-easy. WireGuard is a registered trademark of Jason A. Donenfeld.') }}
				</p>
			</NcSettingsSection>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcPasswordField,
	NcSettingsSection,
	NcTextField,
} from '@nextcloud/vue'

export default {
	name: 'AdminSettings',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcPasswordField,
		NcSettingsSection,
		NcTextField,
	},
	data() {
		return {
			settings: {},
			health: {},
			wgEasyPassword: '',
			geoipApiKey: '',
			loading: true,
			saving: false,
			testing: false,
			testingWgEasy: false,
			message: '',
			error: '',
			testResult: null,
			wgEasyTestResult: null,
			endpointHost: '',
			endpointHostSaved: '',
			endpointPort: null,
			endpointSaving: false,
			endpointMessage: '',
			endpointError: '',
		}
	},
	computed: {
		versionHint() {
			return this.t(
				'nc_wireguard',
				'Peer controller + metrics (v{version}). Poller: occ nc_wireguard:poll-metrics. Engine UI is unpublished; use this app for CRUD.',
			).replace('{version}', __NC_WIREGUARD_VERSION__)
		},
		enginePasswordLabel() {
			const base = this.t('nc_wireguard', 'Engine password')
			if (this.settings.wg_easy_password_configured) {
				return `${base} (${this.t('nc_wireguard', 'configured — leave blank to keep')})`
			}
			return base
		},
		geoipKeyLabel() {
			const base = this.t('nc_wireguard', 'GeoIP API key (ip-api Pro — optional)')
			if (this.settings.geoip_api_key_configured) {
				return `${base} (${this.t('nc_wireguard', 'configured — leave blank to keep')})`
			}
			return base
		},
		dashboardHref() {
			return generateUrl('/apps/nc_wireguard/')
		},
		healthUnknown() {
			return !this.health || this.health.status === 'unknown' || this.health.known === false
		},
		legacyKeys() {
			return Array.isArray(this.health.legacy_keys_present)
				? this.health.legacy_keys_present
				: []
		},
		unusedLegacyKeys() {
			return this.legacyKeys.filter((k) => k === 'dashboard_internal_url')
		},
		wgSyncLegacyKeys() {
			return this.legacyKeys.filter((k) => k === 'wg_sync_url' || k === 'wg_sync_token')
		},
		endpointPortDisplay() {
			return this.endpointPort === null || this.endpointPort === undefined
				? this.t('nc_wireguard', 'unknown')
				: String(this.endpointPort)
		},
		handshakeSummary() {
			const n = this.health.peers_connected
			const m = this.health.peers_total
			const idle = this.health.peers_not_connected
			const never = this.health.peers_never_handshake
			if (n === undefined || n === null || m === undefined || m === null) {
				return this.t('nc_wireguard', 'Peer handshake counts: unknown')
			}
			const neverLabel = (never === null || never === undefined)
				? this.t('nc_wireguard', 'unknown')
				: String(never)
			const idleLabel = (idle === null || idle === undefined)
				? this.t('nc_wireguard', 'unknown')
				: String(idle)
			return `${n} of ${m} peers connected recently (not connected: ${idleLabel}; never: ${neverLabel})`
		},
		showHandshakeWarning() {
			// Only when a stored signal proves never-handshaked (we cannot today).
			const k = this.health.peers_never_handshake
			return typeof k === 'number' && k > 0
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		t(app, s) {
			return typeof window.t === 'function' ? window.t(app, s) : s
		},
		formatJson(value) {
			return JSON.stringify(value, null, 2)
		},
		api(path, opts = {}) {
			return axios({
				url: generateUrl(`/apps/nc_wireguard/api/settings${path}`),
				...opts,
			})
		},
		async load() {
			this.loading = true
			this.error = ''
			this.endpointError = ''
			try {
				const [settingsRes, healthRes, endpointRes] = await Promise.all([
					this.api(''),
					this.api('/health').catch(() => ({ data: { status: 'unknown', status_reason: 'health endpoint unavailable', known: false } })),
					this.api('/endpoint').catch(() => ({ data: { ok: false, host: null, port: null } })),
				])
				this.settings = settingsRes.data
				this.health = healthRes.data || {}
				this.wgEasyPassword = ''
				this.geoipApiKey = ''
				const ep = endpointRes.data || {}
				this.endpointHost = typeof ep.host === 'string' ? ep.host : ''
				this.endpointHostSaved = this.endpointHost
				this.endpointPort = ep.port ?? null
			} catch (e) {
				this.error = e?.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},
		isValidEndpointHost(host) {
			const h = (host || '').trim()
			if (!h || h.length > 253) return false
			if (/[\s\/:#?\\]/.test(h)) return false
			if (/^(\d{1,3}\.){3}\d{1,3}$/.test(h)) {
				return h.split('.').every((o) => {
					const n = Number(o)
					return n >= 0 && n <= 255
				})
			}
			return /^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/.test(h)
		},
		async saveEndpoint() {
			this.endpointSaving = true
			this.endpointMessage = ''
			this.endpointError = ''
			const host = (this.endpointHost || '').trim()
			if (!this.isValidEndpointHost(host)) {
				this.endpointError = this.t(
					'nc_wireguard',
					'Invalid hostname — use an RFC 1123 hostname or IPv4 (no scheme, port, or spaces)',
				)
				this.endpointHost = this.endpointHostSaved
				this.endpointSaving = false
				return
			}
			try {
				await this.ensurePasswordConfirmed()
				const { data } = await this.api('/endpoint', { method: 'POST', data: { host } })
				this.endpointHost = data.host || host
				this.endpointHostSaved = this.endpointHost
				if (data.port !== undefined && data.port !== null) {
					this.endpointPort = data.port
				}
				this.endpointMessage = this.t('nc_wireguard', 'Endpoint host saved.')
			} catch (e) {
				this.endpointMessage = ''
				this.endpointError = e?.response?.data?.error || e.message
				this.endpointHost = this.endpointHostSaved
			} finally {
				this.endpointSaving = false
			}
		},
		payloadForSave() {
			const body = { ...this.settings }
			if (this.wgEasyPassword) {
				body.wg_easy_password = this.wgEasyPassword
			}
			if (this.geoipApiKey) {
				body.geoip_api_key = this.geoipApiKey
			}
			delete body.wg_easy_password_configured
			delete body.geoip_api_key_configured
			return body
		},
		ensurePasswordConfirmed() {
			return new Promise((resolve, reject) => {
				const oc = typeof window !== 'undefined' ? window.OC : undefined
				if (oc?.PasswordConfirmation?.requirePasswordConfirmation) {
					oc.PasswordConfirmation.requirePasswordConfirmation(resolve, {}, reject)
					return
				}
				resolve()
			})
		},
		async save() {
			this.saving = true
			this.message = ''
			this.error = ''
			try {
				await this.ensurePasswordConfirmed()
				const { data } = await this.api('', { method: 'PUT', data: this.payloadForSave() })
				this.settings = data
				this.wgEasyPassword = ''
				this.geoipApiKey = ''
				this.message = 'Settings saved.'
			} catch (e) {
				this.message = ''
				this.error = e?.response?.data?.error || e.message
			} finally {
				this.saving = false
			}
		},
		async test() {
			this.testing = true
			this.testResult = null
			try {
				const { data } = await this.api('/test', { method: 'POST' })
				this.testResult = data
			} catch (e) {
				this.testResult = { ok: false, error: e?.response?.data?.error || e.message }
			} finally {
				this.testing = false
			}
		},
		async testWgEasy() {
			this.testingWgEasy = true
			this.wgEasyTestResult = null
			try {
				if (this.wgEasyPassword) {
					await this.ensurePasswordConfirmed()
					await this.api('', { method: 'PUT', data: this.payloadForSave() })
				}
				const { data } = await this.api('/test-wg-easy', { method: 'POST' })
				this.wgEasyTestResult = data
			} catch (e) {
				this.wgEasyTestResult = {
					ok: false,
					error: e?.response?.data?.error || e.message,
				}
			} finally {
				this.testingWgEasy = false
			}
		},
	},
}
</script>

<style scoped>
.nc-wg-admin-health,
.nc-wg-admin-endpoint {
	margin-bottom: 1.25rem;
	padding-bottom: 0.75rem;
	border-bottom: 1px solid var(--color-border);
}

.nc-wg-admin-select-label {
	display: block;
	margin: 0.75rem 0 0.25rem;
	font-weight: 600;
}

.nc-wg-admin-legacy {
	margin-top: 0.75rem;
}

.nc-wg-admin-actions {
	display: flex;
	gap: 0.75rem;
	margin-top: 1rem;
	flex-wrap: wrap;
}

.muted {
	color: var(--color-text-maxcontrast);
}

.err {
	color: var(--color-error);
}

.nc-wg-legal {
	margin-top: 1.5rem;
	font-size: 0.75rem;
}
</style>
