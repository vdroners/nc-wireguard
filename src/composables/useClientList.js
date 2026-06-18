import { summaryStore } from './useDashboardSummary.js'

export function useClientList() {
	function setClients(list) {
		summaryStore.clients = Array.isArray(list) ? list : []
	}

	return {
		clients: () => summaryStore.clients,
		setClients,
	}
}
