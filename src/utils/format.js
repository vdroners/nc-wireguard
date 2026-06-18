export const CHART_COLORS = ['#dc2626', '#2563eb', '#16a34a', '#d97706', '#9333ea', '#0d9488', '#e11d48', '#4f46e5']

export function fmtBytes(b) {
	if (!b || b === 0) return '0 B'
	const u = ['B', 'KB', 'MB', 'GB', 'TB']
	const i = Math.floor(Math.log(b) / Math.log(1024))
	return (b / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + u[i]
}

export function fmtTime(iso) {
	if (!iso) return '-'
	const d = new Date(iso)
	return d.toLocaleString(undefined, {
		month: 'short',
		day: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
		second: '2-digit',
	})
}

export function timeAgo(iso) {
	if (!iso) return 'never'
	const s = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
	if (s < 60) return s + 's ago'
	if (s < 3600) return Math.floor(s / 60) + 'm ago'
	if (s < 86400) return Math.floor(s / 3600) + 'h ago'
	return Math.floor(s / 86400) + 'd ago'
}
