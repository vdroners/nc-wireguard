import Vue from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const el = document.getElementById('nc-wireguard-admin-root')

const AdminPanel = {
	data() {
		return {
			settings: {},
			loading: true,
			saving: false,
			testing: false,
			message: '',
			error: '',
			testResult: null,
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
			} catch (e) {
				this.error = e?.response?.data?.error || e.message
			} finally {
				this.loading = false
			}
		},
		async save() {
			this.saving = true
			this.message = ''
			this.error = ''
			try {
				const { data } = await this.api('', { method: 'PUT', data: this.settings })
				this.settings = data
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
	},
	template: `
		<div class="nc-wireguard-admin-panel" v-if="!loading">
			<h2>NC WireGuard</h2>
			<p class="muted">Proxy settings for the wg-dashboard sidecar (admin-only app).</p>
			<label>Sidecar internal URL</label>
			<input v-model="settings.dashboard_internal_url" type="url" class="nc-wg-input" />
			<label>wg-easy admin URL</label>
			<input v-model="settings.wg_easy_admin_url" type="url" class="nc-wg-input" />
			<label><input type="checkbox" v-model="settings.dashboard_enabled" /> Dashboard enabled</label>
			<label><input type="checkbox" v-model="settings.watchdog_enabled" /> Sidecar watchdog job</label>
			<label>Connect timeout (s)</label>
			<input v-model.number="settings.dashboard_proxy_connect_timeout" type="number" min="1" max="30" class="nc-wg-input" />
			<label>Request timeout (s)</label>
			<input v-model.number="settings.dashboard_proxy_timeout" type="number" min="5" max="120" class="nc-wg-input" />
			<div class="nc-wg-admin-actions">
				<button type="button" class="nc-wg-btn nc-wg-btn--primary" :disabled="saving" @click="save">Save</button>
				<button type="button" class="nc-wg-btn" :disabled="testing" @click="test">Test connection</button>
			</div>
			<p v-if="message" class="ok">{{ message }}</p>
			<p v-if="error" class="err">{{ error }}</p>
			<pre v-if="testResult" class="nc-wg-test-result">{{ JSON.stringify(testResult, null, 2) }}</pre>
			<p><a :href="generateUrl('/apps/nc_wireguard/')">Open dashboard</a></p>
		</div>
		<p v-else>Loading…</p>
	`,
}

if (el) {
	AdminPanel.methods.generateUrl = generateUrl
	new Vue({ el, render: h => h(AdminPanel) })
}
