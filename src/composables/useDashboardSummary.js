/**
 * Shared dashboard summary + health poll state (Vue 2 observable singleton).
 *
 * WireGuardDashboard starts polling on mount; tabs read summaryStore directly.
 * Do not add parallel summary fetches in tab components.
 */
import Vue from 'vue'
import { fetchSummary, fetchStatus, extractApiError } from '../services/dashboard-api.js'

const POLL_MS = 15000

export const summaryStore = Vue.observable({
	summary: null,
	health: null,
	clients: [],
	loading: false,
	refreshing: false,
	lastUpdate: '',
	error: '',
	forbidden: false,
	serverBootTime: null,
	pollTimer: null,
	visibilityHandler: null,
})

export function getConnectedCount() {
	return summaryStore.summary?.connectedCount ?? 0
}

export function getTotalClients() {
	return summaryStore.summary?.totalClients ?? summaryStore.clients.length
}

export async function fetchDashboardSummary(isInitial = false) {
	if (isInitial) {
		summaryStore.loading = true
	} else {
		summaryStore.refreshing = true
	}
	try {
		const d = await fetchSummary()
		if (d?.error) {
			summaryStore.error = d.error
			return
		}
		summaryStore.summary = d
		summaryStore.clients = d.clients || []
		summaryStore.serverBootTime = d.serverBootTime || null
		summaryStore.error = ''
		summaryStore.forbidden = false
		summaryStore.lastUpdate = new Date().toLocaleTimeString()
	} catch (e) {
		const status = e?.response?.status
		if (status === 403) {
			summaryStore.forbidden = true
		}
		summaryStore.error = extractApiError(e)
	} finally {
		summaryStore.loading = false
		summaryStore.refreshing = false
	}
}

export async function fetchDashboardHealth() {
	try {
		summaryStore.health = await fetchStatus()
	} catch (_) {
		// non-fatal
	}
}

export async function loadAllDashboard(isInitial = false) {
	await Promise.all([fetchDashboardSummary(isInitial), fetchDashboardHealth()])
}

export function startSummaryPolling() {
	stopSummaryPolling()
	loadAllDashboard(true)
	summaryStore.pollTimer = setInterval(() => {
		if (typeof document !== 'undefined' && document.hidden) {
			return
		}
		loadAllDashboard(false)
	}, POLL_MS)
	if (typeof document !== 'undefined') {
		summaryStore.visibilityHandler = () => {
			if (!document.hidden) {
				loadAllDashboard(false)
			}
		}
		document.addEventListener('visibilitychange', summaryStore.visibilityHandler)
	}
}

export function stopSummaryPolling() {
	if (summaryStore.pollTimer) {
		clearInterval(summaryStore.pollTimer)
		summaryStore.pollTimer = null
	}
	if (summaryStore.visibilityHandler && typeof document !== 'undefined') {
		document.removeEventListener('visibilitychange', summaryStore.visibilityHandler)
		summaryStore.visibilityHandler = null
	}
}

export function clearSummaryError() {
	summaryStore.error = ''
}

export function useDashboardSummary() {
	return {
		store: summaryStore,
		startSummaryPolling,
		stopSummaryPolling,
		loadAllDashboard,
		getConnectedCount,
		getTotalClients,
	}
}
