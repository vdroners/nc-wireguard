/**
 * Snapshot of the pre-SFC admin panel (string `template:`).
 * Kept only so fail-first / runtime-only blank render stays reproducible.
 */
export const LegacyAdminPanel = {
	data() {
		return {
			settings: {},
			wgEasyPassword: '',
			geoipApiKey: '',
			loading: false,
			saving: false,
			testing: false,
			testingWgEasy: false,
			message: '',
			error: '',
			testResult: null,
			wgEasyTestResult: null,
		}
	},
	methods: {
		t(_app, s) { return s },
		generateUrl: (p) => p,
	},
	template: `
		<div class="nc-wireguard-admin-panel" v-if="!loading">
			<h2>NC WireGuard</h2>
			<label><input type="checkbox" v-model="settings.dashboard_enabled" /> Dashboard enabled</label>
			<button type="button">Save</button>
		</div>
		<p v-else>Loading…</p>
	`,
}
