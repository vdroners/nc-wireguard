import Vue from 'vue'
import { PiniaVuePlugin, createPinia } from 'pinia'
import { bootstrapNcGcsSettings } from '@/bootstrap/loadGlobalSettings.js'
import AppRoot from './components/AppRoot.vue'

Vue.use(PiniaVuePlugin)

const pinia = createPinia()
const el = document.getElementById('nc-wireguard-root')

if (!el) {
	console.debug('[nc_wireguard] No #nc-wireguard-root — skipping mount.')
} else {
	const enabled = el.dataset.enabled !== '0'
	const wgEasyUrl = el.dataset.wgEasyUrl || 'https://vpn-vdroners.ddns.net/'

	;(async () => {
		try {
			await bootstrapNcGcsSettings(pinia)
		} catch (e) {
			console.warn('[nc_wireguard] settings bootstrap failed:', e?.message || e)
		}
		// eslint-disable-next-line no-new
		new Vue({
			el,
			pinia,
			render: h => h(AppRoot, { props: { enabled, wgEasyUrl } }),
		})
	})()
}
