import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, createLocalVue } from '@vue/test-utils'
import { ncStubs } from './helpers/ncStubs.js'

const axiosMock = vi.hoisted(() => vi.fn())
const vueStubs = vi.hoisted(() => {
	const fieldModel = {
		prop: 'modelValue',
		event: 'update:modelValue',
	}
	return {
		NcSettingsSection: {
			name: 'NcSettingsSection',
			props: ['name', 'description'],
			template: '<section class="stub-settings-section"><h2>{{ name }}</h2><p v-if="description">{{ description }}</p><slot /></section>',
		},
		NcCheckboxRadioSwitch: {
			name: 'NcCheckboxRadioSwitch',
			model: fieldModel,
			props: ['modelValue', 'checked', 'type'],
			template: '<label class="stub-checkbox"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" /><slot /></label>',
		},
		NcTextField: {
			name: 'NcTextField',
			model: fieldModel,
			props: ['value', 'modelValue', 'label', 'type', 'placeholder', 'min', 'max', 'autocomplete'],
			template: '<label class="stub-text-field">{{ label }}<input :type="type || \'text\'" :value="modelValue" :placeholder="placeholder" :min="min" :max="max" @input="$emit(\'update:modelValue\', type === \'number\' ? Number($event.target.value) : $event.target.value)" /></label>',
		},
		NcPasswordField: {
			name: 'NcPasswordField',
			model: fieldModel,
			props: ['value', 'modelValue', 'label', 'placeholder', 'autocomplete'],
			template: '<label class="stub-password-field">{{ label }}<input type="password" :value="modelValue" :placeholder="placeholder" :autocomplete="autocomplete" @input="$emit(\'update:modelValue\', $event.target.value)" /></label>',
		},
		NcButton: {
			name: 'NcButton',
			props: ['disabled', 'type', 'variant'],
			template: '<button type="button" class="stub-button" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
		},
		NcNoteCard: {
			name: 'NcNoteCard',
			props: ['type', 'heading'],
			template: '<div class="stub-note" :data-type="type" role="status"><strong v-if="heading">{{ heading }}</strong><slot /></div>',
		},
	}
})

vi.mock('@nextcloud/axios', () => ({
	default: (...args) => axiosMock(...args),
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
}))
// Avoid pulling real @nextcloud/vue CSS into jsdom; stubs supply the markup.
vi.mock('@nextcloud/vue', () => vueStubs)

const settingsPayload = {
	dashboard_enabled: true,
	wg_easy_admin_url: 'http://127.0.0.1:51821',
	hide_wg_easy_admin_link: true,
	wg_easy_api_url: 'http://wg-easy:51821',
	wg_easy_username: 'admin',
	wg_easy_password_configured: true,
	poll_interval_seconds: 30,
	retention_days: 30,
	geoip_enabled: false,
	geoip_provider: 'ip_api',
	geoip_api_key_configured: true,
	geoip_custom_url: '',
	watchdog_enabled: true,
	watchdog_interval_minutes: 5,
}

const healthPayload = {
	last_poll_at: '2026-11-23T20:00:00+00:00',
	wg_easy_ok: true,
	error_message: null,
	peers_total: 4,
	peers_connected: 2,
	peers_not_connected: 2,
	peers_never_handshake: null,
	legacy_keys_present: ['dashboard_internal_url', 'wg_sync_url', 'wg_sync_token'],
	status: 'ok',
	status_reason: null,
}

const endpointPayload = {
	host: 'cloud-vdroners.ddns.net',
	port: 51820,
}

function mockApis({
	settings = settingsPayload,
	health = healthPayload,
	endpoint = endpointPayload,
	settingsError = null,
	endpointError = null,
	endpointPost = null,
	settingsPut = null,
} = {}) {
	axiosMock.mockImplementation((opts) => {
		const url = typeof opts === 'string' ? opts : opts.url
		const method = (opts.method || 'GET').toUpperCase()
		if (settingsError && method === 'GET' && url.endsWith('/api/settings')) {
			return Promise.reject(settingsError)
		}
		if (method === 'GET' && url.endsWith('/api/settings')) {
			return Promise.resolve({ data: settings })
		}
		if (method === 'GET' && url.includes('/api/settings/health')) {
			return Promise.resolve({ data: health })
		}
		if (method === 'GET' && url.includes('/api/settings/endpoint')) {
			if (endpointError) {
				return Promise.reject(endpointError)
			}
			return Promise.resolve({ data: endpoint })
		}
		if (method === 'POST' && url.includes('/api/settings/endpoint')) {
			if (typeof endpointPost === 'function') {
				return endpointPost(opts)
			}
			return Promise.resolve({ data: { ok: true, host: opts.data.host, port: endpoint.port } })
		}
		if (method === 'PUT' && url.endsWith('/api/settings')) {
			if (typeof settingsPut === 'function') {
				return settingsPut(opts)
			}
			return Promise.resolve({ data: { ...settings, ...opts.data } })
		}
		if (method === 'POST' && url.includes('/test-wg-easy')) {
			return Promise.resolve({ data: { ok: true, client_count: 2 } })
		}
		if (method === 'POST' && url.includes('/test')) {
			return Promise.resolve({ data: { ok: true } })
		}
		return Promise.reject(new Error('unexpected ' + method + ' ' + url))
	})
}

async function mountAdmin() {
	const { default: AdminSettings } = await import('../../src/views/AdminSettings.vue')
	const localVue = createLocalVue()
	const wrapper = mount(AdminSettings, {
		localVue,
		stubs: { ...ncStubs, ...vueStubs },
		attachTo: document.body,
	})
	await flush()
	return wrapper
}

function flush() {
	return new Promise((r) => setTimeout(r, 0))
}

describe('AdminSettings.vue', () => {
	beforeEach(() => {
		axiosMock.mockReset()
		vi.resetModules()
		document.body.innerHTML = ''
		window.OC = {
			PasswordConfirmation: {
				requirePasswordConfirmation: (resolve) => resolve(),
			},
		}
		window.t = (_app, s) => s
	})

	it('renders form fields after load', async () => {
		mockApis()
		const wrapper = await mountAdmin()
		expect(wrapper.find('.nc-wireguard-admin-panel').exists()).toBe(true)
		expect(wrapper.text()).toContain('Dashboard enabled')
		expect(wrapper.text()).toContain('Engine API URL')
		expect(wrapper.text()).toContain('Poll interval')
		expect(wrapper.text()).toContain('Retention')
		expect(wrapper.text()).toContain('GeoIP')
		expect(wrapper.text()).toContain('Watchdog')
		expect(wrapper.text()).toContain('Save')
		expect(wrapper.text()).toContain('Test native health')
		expect(wrapper.text()).toContain('Test engine')
		expect(wrapper.text()).toContain('Open dashboard')
		expect(wrapper.text()).toMatch(/Not affiliated with or endorsed by WireGuard/)
		wrapper.destroy()
	})

	it('shows configured hints and keeps password inputs empty', async () => {
		mockApis()
		const wrapper = await mountAdmin()
		expect(wrapper.text()).toMatch(/configured — leave blank to keep/)
		const passwords = wrapper.findAll('input[type="password"]')
		expect(passwords.length).toBeGreaterThanOrEqual(2)
		passwords.wrappers.forEach((input) => {
			expect(input.element.value).toBe('')
		})
		wrapper.destroy()
	})

	it('version label uses __NC_WIREGUARD_VERSION__, not v2.3.1', async () => {
		mockApis()
		const wrapper = await mountAdmin()
		expect(wrapper.text()).toContain(__NC_WIREGUARD_VERSION__)
		expect(wrapper.text()).not.toContain('v2.3.1')
		wrapper.destroy()
	})

	it('Save PUT omits *_configured and empty secrets; includes typed ones', async () => {
		mockApis()
		const wrapper = await mountAdmin()
		await wrapper.findAll('.stub-button').wrappers.find((b) => b.text() === 'Save').trigger('click')
		await flush()
		const putCall = axiosMock.mock.calls.find((c) => (c[0].method || '').toUpperCase() === 'PUT')
		expect(putCall).toBeTruthy()
		const body = putCall[0].data
		expect(body).not.toHaveProperty('wg_easy_password_configured')
		expect(body).not.toHaveProperty('geoip_api_key_configured')
		expect(body).not.toHaveProperty('wg_easy_password')
		expect(body).not.toHaveProperty('geoip_api_key')

		const pwInputs = wrapper.findAll('input[type="password"]')
		await pwInputs.at(0).setValue('new-engine-secret')
		await pwInputs.at(1).setValue('new-geoip-key')
		await wrapper.findAll('.stub-button').wrappers.find((b) => b.text() === 'Save').trigger('click')
		await flush()
		const putCalls = axiosMock.mock.calls.filter((c) => (c[0].method || '').toUpperCase() === 'PUT')
		const body2 = putCalls[putCalls.length - 1][0].data
		expect(body2.wg_easy_password).toBe('new-engine-secret')
		expect(body2.geoip_api_key).toBe('new-geoip-key')
		expect(body2).not.toHaveProperty('wg_easy_password_configured')
		wrapper.destroy()
	})

	it('Test engine posts /test-wg-easy and only PUTs first when password typed', async () => {
		mockApis()
		const wrapper = await mountAdmin()
		axiosMock.mockClear()
		await wrapper.findAll('.stub-button').wrappers.find((b) => b.text() === 'Test engine').trigger('click')
		await flush()
		const calls = axiosMock.mock.calls.map((c) => [(c[0].method || 'GET').toUpperCase(), c[0].url])
		expect(calls.some(([m, u]) => m === 'POST' && u.includes('/test-wg-easy'))).toBe(true)
		expect(calls.some(([m]) => m === 'PUT')).toBe(false)

		axiosMock.mockClear()
		await wrapper.findAll('input[type="password"]').at(0).setValue('typed-pass')
		await wrapper.findAll('.stub-button').wrappers.find((b) => b.text() === 'Test engine').trigger('click')
		await flush()
		const calls2 = axiosMock.mock.calls.map((c) => [(c[0].method || 'GET').toUpperCase(), c[0].url])
		expect(calls2.some(([m]) => m === 'PUT')).toBe(true)
		expect(calls2.some(([m, u]) => m === 'POST' && u.includes('/test-wg-easy'))).toBe(true)
		wrapper.destroy()
	})

	it('load error shows an error note', async () => {
		mockApis({
			settingsError: { response: { data: { error: 'boom-load' } }, message: 'boom-load' },
		})
		const wrapper = await mountAdmin()
		expect(wrapper.find('.stub-note[data-type="error"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('boom-load')
		wrapper.destroy()
	})

	it('Health card shows connected vs not-connected; never unknown; warning when not-connected > 0', async () => {
		mockApis()
		const wrapper = await mountAdmin()
		expect(wrapper.text()).toMatch(/2 of 4 peers connected recently/)
		expect(wrapper.text()).toMatch(/not connected: 2/)
		expect(wrapper.text()).toMatch(/never: unknown/)
		const warn = wrapper.find('.stub-note[data-type="warning"]')
		expect(warn.exists()).toBe(true)
		expect(warn.text()).toMatch(/Peers without a recent handshake/)
		expect(warn.text()).toMatch(/2 of 4 peers have not handshaken recently/)
		const legacy = wrapper.find('[data-testid="legacy-keys"]')
		expect(legacy.exists()).toBe(true)
		expect(legacy.text()).toContain('dashboard_internal_url')
		expect(legacy.text()).toContain('wg_sync_url')
		expect(legacy.text()).toContain('wg_sync_token')
		expect(legacy.text()).toMatch(/unused; safe to remove with occ config:app:delete after review/)
		expect(legacy.text()).toMatch(/still used by the optional lab\/native wg-sync path/)
		expect(legacy.text()).not.toMatch(/enc:v1:|http:\/\/|token-value|secret-value/i)
		wrapper.destroy()
	})

	it('Health card hides handshake warning when all peers are connected', async () => {
		mockApis({
			health: {
				...healthPayload,
				peers_total: 3,
				peers_connected: 3,
				peers_not_connected: 0,
				peers_never_handshake: null,
			},
		})
		const wrapper = await mountAdmin()
		expect(wrapper.text()).toMatch(/3 of 3 peers connected recently/)
		expect(wrapper.find('.stub-note[data-type="warning"]').exists()).toBe(false)
		wrapper.destroy()
	})

	it('stale health still shows poll, error, summary and warning under Status unknown', async () => {
		mockApis({
			health: {
				status: 'unknown',
				known: true,
				status_reason: 'last poll older than 60s — treating as unknown',
				last_poll_at: '2026-11-23T12:00:00+00:00',
				wg_easy_ok: null,
				error_message: 'poll timed out',
				peers_total: 6,
				peers_connected: 0,
				peers_not_connected: 6,
				peers_never_handshake: null,
				legacy_keys_present: [],
			},
		})
		const wrapper = await mountAdmin()
		expect(wrapper.text()).toMatch(/Status: unknown/)
		expect(wrapper.text()).toMatch(/last poll older than 60s/)
		expect(wrapper.text()).toMatch(/wg_easy_ok:\s*unknown/)
		expect(wrapper.text()).toContain('2026-11-23T12:00:00+00:00')
		expect(wrapper.text()).toMatch(/Last error:\s*poll timed out/)
		expect(wrapper.text()).toMatch(/0 of 6 peers connected recently/)
		expect(wrapper.find('.stub-note[data-type="warning"]').exists()).toBe(true)
		expect(wrapper.text()).toMatch(/6 of 6 peers have not handshaken recently/)
		wrapper.destroy()
	})

	it('shows VPN endpoint host from API; port read-only', async () => {
		mockApis()
		const wrapper = await mountAdmin()
		expect(wrapper.text()).toContain('VPN endpoint')
		const hostInput = wrapper.find('[data-testid="endpoint-host"] input')
		expect(hostInput.exists()).toBe(true)
		expect(hostInput.element.value).toBe('cloud-vdroners.ddns.net')
		expect(wrapper.text()).toMatch(/51820/)
		expect(wrapper.text()).toMatch(/Changing this changes the Endpoint in every newly downloaded client config/)
		wrapper.destroy()
	})

	it('endpoint GET failure shows unknown host with reason', async () => {
		mockApis({
			endpointError: { response: { data: { error: 'endpoint unavailable' } }, message: 'endpoint unavailable' },
		})
		const wrapper = await mountAdmin()
		expect(wrapper.text()).toMatch(/Current endpoint host:\s*unknown/)
		expect(wrapper.text()).toMatch(/endpoint unavailable|endpoint could not be read from the engine/)
		expect(wrapper.find('[data-testid="endpoint-host"] input').element.value).toBe('')
		wrapper.destroy()
	})

	it('endpoint host null shows unknown with reason and does not treat blank as saved', async () => {
		mockApis({
			endpoint: { ok: true, host: null, port: 51820, error: 'host missing in userconfig' },
		})
		const wrapper = await mountAdmin()
		expect(wrapper.text()).toMatch(/Current endpoint host:\s*unknown/)
		expect(wrapper.text()).toContain('host missing in userconfig')
		const hostInput = wrapper.find('[data-testid="endpoint-host"] input')
		await hostInput.setValue('not valid!!')
		await wrapper.find('[data-testid="endpoint-save"]').trigger('click')
		await flush()
		expect(hostInput.element.value).toBe('')
		expect(wrapper.find('.stub-note[data-type="error"]').text()).toMatch(/Invalid hostname/)
		wrapper.destroy()
	})

	it('invalid hostnames block Save with error note and send no POST', async () => {
		mockApis()
		const wrapper = await mountAdmin()
		axiosMock.mockClear()
		const hostInput = wrapper.find('[data-testid="endpoint-host"] input')
		for (const bad of ['https://x', 'a b', 'host:51820', '', '999.1.1.1', '1.2.3']) {
			await hostInput.setValue(bad)
			await wrapper.find('[data-testid="endpoint-save"]').trigger('click')
			await flush()
			const errNote = wrapper.findAll('.stub-note[data-type="error"]').wrappers
				.find((n) => n.text().includes('Invalid hostname'))
			expect(errNote).toBeTruthy()
			expect(errNote.text()).toContain('Invalid hostname')
			const posts = axiosMock.mock.calls.filter((c) => (c[0].method || '').toUpperCase() === 'POST')
			expect(posts).toHaveLength(0)
		}
		wrapper.destroy()
	})

	it('failed settings PUT clears Saved and shows error', async () => {
		let putCount = 0
		mockApis({
			settingsPut: (opts) => {
				putCount++
				if (putCount === 1) {
					return Promise.resolve({ data: { ...settingsPayload, ...opts.data } })
				}
				return Promise.reject({ response: { data: { error: 'save refused' } }, message: 'save refused' })
			},
		})
		const wrapper = await mountAdmin()
		await wrapper.findAll('.stub-button').wrappers.find((b) => b.text() === 'Save').trigger('click')
		await flush()
		expect(wrapper.find('.stub-note[data-type="success"]').exists()).toBe(true)
		expect(wrapper.text()).toMatch(/Saved/)
		await wrapper.findAll('.stub-button').wrappers.find((b) => b.text() === 'Save').trigger('click')
		await flush()
		expect(wrapper.find('.stub-note[data-type="success"]').exists()).toBe(false)
		expect(wrapper.find('.stub-note[data-type="error"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('save refused')
		wrapper.destroy()
	})

	it('valid host POSTs only {host}; success note only after 2xx; failure keeps old value', async () => {
		let postCount = 0
		mockApis({
			endpointPost: (opts) => {
				postCount++
				if (postCount === 1) {
					return Promise.reject({ response: { data: { error: 'engine refused' } }, message: 'engine refused' })
				}
				return Promise.resolve({ data: { ok: true, host: opts.data.host, port: 51820 } })
			},
		})
		const wrapper = await mountAdmin()
		const hostInput = wrapper.find('[data-testid="endpoint-host"] input')
		expect(hostInput.element.value).toBe('cloud-vdroners.ddns.net')

		await hostInput.setValue('vpn.new.example')
		axiosMock.mockClear()
		await wrapper.find('[data-testid="endpoint-save"]').trigger('click')
		await flush()
		const post1 = axiosMock.mock.calls.find((c) => (c[0].method || '').toUpperCase() === 'POST')
		expect(post1).toBeTruthy()
		expect(Object.keys(post1[0].data)).toEqual(['host'])
		expect(post1[0].data.host).toBe('vpn.new.example')
		expect(wrapper.text()).toContain('engine refused')
		expect(wrapper.find('.stub-note[data-type="success"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="endpoint-host"] input').element.value).toBe('cloud-vdroners.ddns.net')

		await hostInput.setValue('vpn.new.example')
		axiosMock.mockClear()
		await wrapper.find('[data-testid="endpoint-save"]').trigger('click')
		await flush()
		expect(wrapper.find('.stub-note[data-type="success"]').exists()).toBe(true)
		expect(wrapper.text()).toMatch(/Endpoint saved|endpoint host saved/i)
		expect(wrapper.find('[data-testid="endpoint-host"] input').element.value).toBe('vpn.new.example')
		wrapper.destroy()
	})
})

describe('build guard', () => {
	it('admin bundle must not ship raw string template marker', async () => {
		const fs = await import('node:fs')
		const path = await import('node:path')
		const bundle = path.resolve(__dirname, '../../js/nc_wireguard-admin.js')
		if (!fs.existsSync(bundle)) {
			expect.fail('js/nc_wireguard-admin.js missing — run npm run build first')
		}
		const src = fs.readFileSync(bundle, 'utf8')
		expect(src).not.toContain('nc-wireguard-admin-panel" v-if')
	})
})
