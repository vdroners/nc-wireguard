import { ref, readonly } from 'vue'

const clients = ref([])

export function useClientList() {
	function setClients(list) {
		clients.value = Array.isArray(list) ? list : []
	}

	return {
		clients: readonly(clients),
		setClients,
	}
}
