import Vue from 'vue'
import AdminSettings from './views/AdminSettings.vue'

const el = document.getElementById('nc-wireguard-admin-root')

if (el) {
	new Vue({
		el,
		render: (h) => h(AdminSettings),
	})
}
