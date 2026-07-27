import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = () => generateUrl('/apps/nc_wireguard/api/dashboard')
const peersBase = () => generateUrl('/apps/nc_wireguard/api/peers')

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
	try {
		const { data } = await axios.get(`${peersBase()}/${clientId}/configuration`)
		return data
	} catch (_) {
		const { data } = await axios.get(generateUrl(`/apps/nc_wireguard/api/wg-easy/${clientId}/configuration`))
		return data
	}
}

export async function createPeer(payload) {
	const { data } = await axios.post(peersBase(), payload)
	return data
}

export async function updatePeer(clientId, payload) {
	const { data } = await axios.post(`${peersBase()}/${clientId}`, payload)
	return data
}

export async function deletePeer(clientId) {
	const { data } = await axios.delete(`${peersBase()}/${clientId}`)
	return data
}

export async function enablePeer(clientId) {
	const { data } = await axios.post(`${peersBase()}/${clientId}/enable`)
	return data
}

export async function disablePeer(clientId) {
	const { data } = await axios.post(`${peersBase()}/${clientId}/disable`)
	return data
}

export async function generatePeerOtl(clientId) {
	const { data } = await axios.post(`${peersBase()}/${clientId}/one-time-link`)
	return data
}

export function extractApiError(err) {
	if (err?.response?.data?.error) return String(err.response.data.error)
	if (err?.response?.data?.message) return String(err.response.data.message)
	if (err?.message) return err.message
	return 'Request failed'
}

/** Field / site GCS split-tunnel defaults */
export const PRESET_FIELD = {
	allowedIps: '10.0.0.0/24,10.8.0.0/24',
	dns: '',
	mtu: 1420,
	persistentKeepalive: 25,
}

/** Admin full-tunnel (IPv4 only) */
export const PRESET_ADMIN = {
	allowedIps: '0.0.0.0/0',
	dns: '1.1.1.1',
	mtu: 1420,
	persistentKeepalive: 25,
}
