import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, createLocalVue } from '@vue/test-utils'

const fetchServerDefaults = vi.hoisted(() => vi.fn())
const fetchSystem = vi.hoisted(() => vi.fn())

vi.mock('../../src/services/dashboard-api.js', () => ({
	fetchServerDefaults: (...args) => fetchServerDefaults(...args),
	fetchSystem: (...args) => fetchSystem(...args),
	extractApiError: (e) => e?.message || 'error',
}))

vi.mock('../../src/composables/useDashboardSummary.js', () => ({
	summaryStore: { health: {} },
}))

vi.mock('../../src/utils/bandwidth-rates.js', () => ({
	buildSystemNetRates: () => ({ netRx: [], netTx: [] }),
}))

// Render fns — vitest aliases runtime-only Vue (no string-template compile).
function stub(name, className) {
	return {
		default: {
			name,
			props: ['message'],
			render(h) {
				return h('div', { class: className })
			},
		},
	}
}

vi.mock('../../src/components/common/ErrorBanner.vue', () => stub('ErrorBanner', 'stub-error-banner'))
vi.mock('../../src/components/common/LoadingState.vue', () => stub('LoadingState', 'stub-loading'))
vi.mock('../../src/components/common/RateChart.vue', () => stub('RateChart', 'stub-rate-chart'))
vi.mock('../../src/components/HistoryToolbar.vue', () => stub('HistoryToolbar', 'stub-history-toolbar'))

async function mountSystemTab(server) {
	fetchServerDefaults.mockResolvedValue(server)
	fetchSystem.mockResolvedValue([])
	const { default: SystemTab } = await import('../../src/components/tabs/SystemTab.vue')
	const localVue = createLocalVue()
	const wrapper = mount(SystemTab, {
		localVue,
		propsData: { active: false },
		attachTo: document.body,
	})
	await wrapper.setProps({ active: true })
	await flush()
	await flush()
	return wrapper
}

function flush() {
	return new Promise((r) => setTimeout(r, 0))
}

describe('SystemTab.vue endpoint display', () => {
	beforeEach(() => {
		vi.resetModules()
		fetchServerDefaults.mockReset()
		fetchSystem.mockReset()
		document.body.innerHTML = ''
	})

	it('shows unknown with endpointError when host is null', async () => {
		const wrapper = await mountSystemTab({
			ok: true,
			unavailable: false,
			host: null,
			endpoint: null,
			port: null,
			endpointError: 'userconfig could not be read',
			adminUiHost: '127.0.0.1',
			notes: [],
		})
		expect(wrapper.text()).toMatch(/Endpoint \(in client configs\)/)
		expect(wrapper.text()).toMatch(/unknown\s*—\s*userconfig could not be read/)
		expect(wrapper.text()).toMatch(/UDP port/)
		expect(wrapper.text()).toMatch(/unknown/)
		wrapper.destroy()
	})
})
