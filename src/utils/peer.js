const EXPIRING_DAYS = 7

export function isExpiringSoon(expiresAt) {
	if (!expiresAt) return false
	const ms = new Date(expiresAt).getTime() - Date.now()
	return ms > 0 && ms < EXPIRING_DAYS * 86400000
}

export function countryFlag(code) {
	if (!code || code.length !== 2) return ''
	const base = 0x1F1E6
	const upper = code.toUpperCase()
	return String.fromCodePoint(
		base + upper.charCodeAt(0) - 65,
		base + upper.charCodeAt(1) - 65,
	)
}

export function peerRowClass(client) {
	const classes = []
	if (client.enabled === false) {
		classes.push('nc-wg-peer--disabled')
	}
	if (isExpiringSoon(client.expiresAt)) {
		classes.push('nc-wg-peer--expiring')
	}
	return classes
}
