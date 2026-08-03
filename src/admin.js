import Vue from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
// @nextcloud/axios attaches the Nextcloud requesttoken (CSRF) on mutating calls.

const el = document.getElementById('nc-wireguard-admin-root')

const AdminPanel = {
	data() {
		return {
			settings: {},
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
		}
	},
	async mounted() {
		await this.load()
	},
	methods: {
		// The template calls t(), but @nextcloud/l10n is not a dependency of this
		// app. Nextcloud's server-rendered page defines a global t(); use it when
		// present so real translations still apply, and fall back to the source
		// string otherwise. Without this the template resolves t against the
		// component instance and throws in the Vue dev build.
		t(app, s) {
			return typeof window.t === 'function' ? window.t(app, s) : s
		},
		api(path, opts = {}) {
			return axios({ url: generateUrl(`/apps/nc_wireguard/api/settings${path}`), ...opts })
		},
		async load() {
			this.loading = true
			try {
				const { data } = await this.api('')
				this.settings = data
				this.wgEasyPassword = ''
				this.geoipApiKey = ''
			} catch (e) {
				this.error = e?.response?.data?.error || e.message
			} finally {
				this.loading = false
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
			// PasswordConfirmationRequired on saveSettings — use core OC helper
			// (avoids bundling @nextcloud/dialogs into the admin entry).
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
	template: `
		<div class="nc-wireguard-admin-panel" v-if="!loading">
			<h2>NC WireGuard</h2>
			<p class="muted">{{ t('nc_wireguard', 'Peer controller + metrics (v2.3.1). Poller: occ nc_wireguard:poll-metrics. Engine UI is unpublished; use this app for CRUD.') }}</p>

			<h3>{{ t('nc_wireguard', 'Dashboard') }}</h3>
			<label><input type="checkbox" v-model="settings.dashboard_enabled" /> {{ t('nc_wireguard', 'Dashboard enabled') }}</label>
			<label>{{ t('nc_wireguard', 'Engine admin URL (optional break-glass deep link)') }}</label>
			<input v-model="settings.wg_easy_admin_url" type="url" class="nc-wg-input" />
			<label><input type="checkbox" v-model="settings.hide_wg_easy_admin_link" /> {{ t('nc_wireguard', 'Hide engine admin link in dashboard (recommended)') }}</label>

			<h3>{{ t('nc_wireguard', 'WireGuard engine API (poller + writes)') }}</h3>
			<label>{{ t('nc_wireguard', 'Engine API URL (internal Docker)') }}</label>
			<input v-model="settings.wg_easy_api_url" type="url" class="nc-wg-input" />
			<label>{{ t('nc_wireguard', 'Engine username') }}</label>
			<input v-model="settings.wg_easy_username" type="text" class="nc-wg-input" autocomplete="off" />
			<label>{{ t('nc_wireguard', 'Engine password') }} <span v-if="settings.wg_easy_password_configured" class="muted">({{ t('nc_wireguard', 'configured — leave blank to keep') }})</span></label>
			<input v-model="wgEasyPassword" type="password" class="nc-wg-input" autocomplete="new-password" />
			<p class="muted">{{ t('nc_wireguard', 'TOTP must stay off on the API service account.') }}</p>
			<label>{{ t('nc_wireguard', 'Poll interval (seconds)') }}</label>
			<input v-model.number="settings.poll_interval_seconds" type="number" min="10" max="300" class="nc-wg-input" />
			<label>{{ t('nc_wireguard', 'Retention (days)') }}</label>
			<input v-model.number="settings.retention_days" type="number" min="1" max="365" class="nc-wg-input" />
			<label><input type="checkbox" v-model="settings.geoip_enabled" /> {{ t('nc_wireguard', 'GeoIP lookups on connect (off by default)') }}</label>
			<p class="muted">{{ t('nc_wireguard', 'When enabled, peer public IPs may be sent to a GeoIP provider. ip-api.com free tier is HTTP-only and non-commercial; use a Pro API key or custom HTTPS endpoint for production.') }}</p>
			<label>{{ t('nc_wireguard', 'GeoIP provider') }}</label>
			<select v-model="settings.geoip_provider" class="nc-wg-input">
				<option value="ip_api">ip-api.com (default template)</option>
				<option value="custom">{{ t('nc_wireguard', 'Custom URL template') }}</option>
			</select>
			<label>{{ t('nc_wireguard', 'GeoIP API key (ip-api Pro — optional)') }}</label>
			<input v-model="geoipApiKey" type="password" class="nc-wg-input" autocomplete="new-password" :placeholder="t('nc_wireguard', 'Leave blank to keep existing')" />
			<label>{{ t('nc_wireguard', 'Custom GeoIP URL (use {ip} and {fields} placeholders)') }}</label>
			<input v-model="settings.geoip_custom_url" type="url" class="nc-wg-input" placeholder="https://example.com/geoip/{ip}?fields={fields}" />

			<h3>{{ t('nc_wireguard', 'Metrics health watchdog') }}</h3>
			<p class="muted">{{ t('nc_wireguard', 'Background job logs stale polls and engine failures (see Nextcloud log).') }}</p>
			<label><input type="checkbox" v-model="settings.watchdog_enabled" /> {{ t('nc_wireguard', 'Watchdog job enabled') }}</label>
			<label>{{ t('nc_wireguard', 'Watchdog interval (minutes)') }}</label>
			<input v-model.number="settings.watchdog_interval_minutes" type="number" min="1" max="60" class="nc-wg-input" />

			<div class="nc-wg-admin-actions">
				<button type="button" class="nc-wg-btn nc-wg-btn--primary" :disabled="saving" @click="save">{{ t('nc_wireguard', 'Save') }}</button>
				<button type="button" class="nc-wg-btn" :disabled="testing" @click="test">{{ t('nc_wireguard', 'Test native health') }}</button>
				<button type="button" class="nc-wg-btn" :disabled="testingWgEasy" @click="testWgEasy">{{ t('nc_wireguard', 'Test engine') }}</button>
			</div>
			<p v-if="message" class="ok">{{ message }}</p>
			<p v-if="error" class="err">{{ error }}</p>
			<pre v-if="testResult" class="nc-wg-test-result">{{ t('nc_wireguard', 'Native health') }}: {{ JSON.stringify(testResult, null, 2) }}</pre>
			<pre v-if="wgEasyTestResult" class="nc-wg-test-result">{{ t('nc_wireguard', 'Engine') }}: {{ JSON.stringify(wgEasyTestResult, null, 2) }}</pre>
			<p><a :href="generateUrl('/apps/nc_wireguard/')">{{ t('nc_wireguard', 'Open dashboard') }}</a></p>
			<p class="muted nc-wg-legal">{{ t('nc_wireguard', 'Not affiliated with or endorsed by WireGuard or wg-easy. WireGuard is a registered trademark of Jason A. Donenfeld.') }}</p>
		</div>
		<p v-else>{{ t('nc_wireguard', 'Loading…') }}</p>
	`,
}

if (el) {
	AdminPanel.methods.generateUrl = generateUrl
	new Vue({ el, render: h => h(AdminPanel) })
}
