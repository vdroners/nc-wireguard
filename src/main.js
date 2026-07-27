import Vue from 'vue'
import { PiniaVuePlugin, createPinia } from 'pinia'
import AppRoot from './components/AppRoot.vue'

Vue.use(PiniaVuePlugin)

const pinia = createPinia()
const el = document.getElementById('nc-wireguard-root')

if (!el) {
	console.debug('[nc_wireguard] No #nc-wireguard-root — skipping mount.')
} else {
	const enabled = el.dataset.enabled !== '0'
	const wgEasyUrl = el.dataset.wgEasyUrl || ''
	const hideWgEasy = el.dataset.hideWgEasy === '1'

	new Vue({
		el,
		pinia,
		render: h => h(AppRoot, { props: { enabled, wgEasyUrl, hideWgEasy } }),
	})
}
