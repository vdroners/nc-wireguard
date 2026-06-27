import Vue from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const el = document.getElementById('nc-wireguard-admin-root')

const AdminPanel = {
	data() {
		return {
			settings: {},
			wgEasyPassword: '',
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
		api(path, opts = {}) {
			return axios({ url: generateUrl(`/apps/nc_wireguard/api/settings${path}`), ...opts })
		},
		async load() {
			this.loading = true
			try {
				const { data } = await this.api('')
				this.settings = data
				this.wgEasyPassword = ''
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
			delete body.wg_easy_password_configured
			return body
		},
		async save() {
			this.saving = true
			this.message = ''
			this.error = ''
			try {
				const { data } = await this.api('', { method: 'PUT', data: this.payloadForSave() })
				this.settings = data
				this.wgEasyPassword = ''
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
			<p class="muted">Native backend v2.0 — dashboard routes read from Nextcloud metrics tables populated by <code>occ nc_wireguard:poll-metrics</code>.</p>

			<h3>Dashboard</h3>
			<label><input type="checkbox" v-model="settings.dashboard_enabled" /> Dashboard enabled</label>
			<label>wg-easy admin URL (external UI link)</label>
			<input v-model="settings.wg_easy_admin_url" type="url" class="nc-wg-input" />

			<h3>wg-easy API (poller)</h3>
			<label>wg-easy API URL (internal)</label>
			<input v-model="settings.wg_easy_api_url" type="url" class="nc-wg-input" />
			<label>wg-easy username</label>
			<input v-model="settings.wg_easy_username" type="text" class="nc-wg-input" autocomplete="off" />
			<label>wg-easy password <span v-if="settings.wg_easy_password_configured" class="muted">(configured — leave blank to keep)</span></label>
			<input v-model="wgEasyPassword" type="password" class="nc-wg-input" autocomplete="new-password" />
			<label>Poll interval (seconds)</label>
			<input v-model.number="settings.poll_interval_seconds" type="number" min="10" max="300" class="nc-wg-input" />
			<label>Retention (days)</label>
			<input v-model.number="settings.retention_days" type="number" min="1" max="365" class="nc-wg-input" />
			<label><input type="checkbox" v-model="settings.geoip_enabled" /> GeoIP lookups on connect</label>

			<h3>Metrics health watchdog</h3>
			<p class="muted">Background job logs stale polls and wg-easy failures (see Nextcloud log).</p>
			<label><input type="checkbox" v-model="settings.watchdog_enabled" /> Watchdog job enabled</label>
			<label>Watchdog interval (minutes)</label>
			<input v-model.number="settings.watchdog_interval_minutes" type="number" min="1" max="60" class="nc-wg-input" />

			<div class="nc-wg-admin-actions">
				<button type="button" class="nc-wg-btn nc-wg-btn--primary" :disabled="saving" @click="save">Save</button>
				<button type="button" class="nc-wg-btn" :disabled="testing" @click="test">Test native health</button>
				<button type="button" class="nc-wg-btn" :disabled="testingWgEasy" @click="testWgEasy">Test wg-easy</button>
			</div>
			<p v-if="message" class="ok">{{ message }}</p>
			<p v-if="error" class="err">{{ error }}</p>
			<pre v-if="testResult" class="nc-wg-test-result">Native health: {{ JSON.stringify(testResult, null, 2) }}</pre>
			<pre v-if="wgEasyTestResult" class="nc-wg-test-result">wg-easy: {{ JSON.stringify(wgEasyTestResult, null, 2) }}</pre>
			<p><a :href="generateUrl('/apps/nc_wireguard/')">Open dashboard</a></p>
		</div>
		<p v-else>Loading…</p>
	`,
}

if (el) {
	AdminPanel.methods.generateUrl = generateUrl
	new Vue({ el, render: h => h(AdminPanel) })
}
