import Vue from 'vue'
import { PiniaVuePlugin, createPinia } from 'pinia'
import NcGcsAppShell from '@/components/common/NcGcsAppShell.vue'
import { bootstrapNcGcsSettings } from '@/bootstrap/loadGlobalSettings.js'
import WireGuardDashboard from './components/WireGuardDashboard.vue'

Vue.use(PiniaVuePlugin)

const pinia = createPinia()
const el = document.getElementById('nc-wireguard-root')

if (!el) {
	console.debug('[nc_wireguard] No #nc-wireguard-root — skipping mount.')
} else {
	const enabled = el.dataset.enabled !== '0'
	const wgEasyUrl = el.dataset.wgEasyUrl || 'https://vpn-vdroners.ddns.net/'

	const ShellRender = {
		render(h) {
			return h(NcGcsAppShell, {
				props: {
					appId: 'nc_wireguard',
					title: 'NC WireGuard',
					accent: '#dc2626',
					subtitle: 'VPN server monitoring',
				},
				scopedSlots: {
					'banner-extra': () => wgEasyUrl
						? h('a', {
							attrs: {
								href: wgEasyUrl,
								target: '_blank',
								rel: 'noopener',
								class: 'nc-wg-admin-link',
							},
						}, 'Open wg-easy admin')
						: null,
				},
			}, [h(WireGuardDashboard, { props: { enabled } })])
		},
	}

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
			render: h => h(ShellRender),
		})
	})()
}
