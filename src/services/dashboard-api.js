import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = () => generateUrl('/apps/nc_wireguard/api/dashboard')

export async function fetchSummary() {
	const { data } = await axios.get(`${base()}/summary`)
	return data
}

export async function fetchBandwidth(params = {}) {
	const { data } = await axios.get(`${base()}/bandwidth`, { params })
	return data
}

export async function fetchConnections(params = {}) {
	const { data } = await axios.get(`${base()}/connections`, { params })
	return data
}

export async function fetchGeoip() {
	const { data } = await axios.get(`${base()}/geoip`)
	return data
}

export async function fetchSystem(params = {}) {
	const { data } = await axios.get(`${base()}/system`, { params })
	return data
}

export async function fetchStatus() {
	const { data } = await axios.get(generateUrl('/apps/nc_wireguard/api/status'))
	return data
}

export async function fetchPeerConfig(clientId) {
	const { data } = await axios.get(generateUrl(`/apps/nc_wireguard/api/wg-easy/${clientId}/configuration`))
	return data
}

export function extractApiError(err) {
	if (err?.response?.data?.error) return String(err.response.data.error)
	if (err?.response?.data?.message) return String(err.response.data.message)
	if (err?.message) return err.message
	return 'Request failed'
}
