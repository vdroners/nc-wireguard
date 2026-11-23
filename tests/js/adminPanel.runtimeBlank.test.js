/**
 * Fail-first / regression: Vue runtime-only cannot compile string `template:` options.
 * Production webpack ships runtime-only; string templates render nothing.
 */
import { describe, it, expect } from 'vitest'
import Vue from 'vue'
import { LegacyAdminPanel } from './fixtures/legacyAdminPanel.js'

describe('AdminPanel runtime-only blank render (fail-first)', () => {
	it('string template panel renders no form fields under runtime-only Vue', () => {
		expect(Vue.compile).toBeUndefined()

		const warnings = []
		const prev = Vue.config.warnHandler
		Vue.config.warnHandler = (msg) => { warnings.push(msg) }

		const vm = new Vue(LegacyAdminPanel)
		vm.$mount()

		Vue.config.warnHandler = prev

		const html = vm.$el?.outerHTML || ''
		const text = vm.$el?.textContent || ''

		expect(text).not.toContain('Dashboard enabled')
		expect(text).not.toContain('Save')
		expect(html.includes('nc-wireguard-admin-panel')).toBe(false)
		expect(
			warnings.some((w) => /runtime-only|template|compiler/i.test(w))
			|| html === ''
			|| html === '<!-- -->'
			|| (vm.$el && vm.$el.nodeType === 8),
		).toBe(true)
	})
})
