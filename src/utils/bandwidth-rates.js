import { CHART_COLORS } from './format.js'

/**
 * Group bandwidth rows by client name and compute per-interval rates.
 */
export function buildBandwidthRateDatasets(rows) {
	const grouped = {}
	for (const row of rows) {
		if (!grouped[row.name]) grouped[row.name] = []
		grouped[row.name].push(row)
	}

	const rxDatasets = []
	const txDatasets = []
	let ci = 0
	for (const [name, clientRows] of Object.entries(grouped)) {
		const color = CHART_COLORS[ci % CHART_COLORS.length]
		const rxRates = []
		const txRates = []
		for (let i = 1; i < clientRows.length; i++) {
			const dt = (new Date(clientRows[i].ts) - new Date(clientRows[i - 1].ts)) / 1000
			if (dt > 0) {
				rxRates.push({
					x: new Date(clientRows[i].ts),
					y: Math.max(0, (clientRows[i].transfer_rx - clientRows[i - 1].transfer_rx) / dt),
				})
				txRates.push({
					x: new Date(clientRows[i].ts),
					y: Math.max(0, (clientRows[i].transfer_tx - clientRows[i - 1].transfer_tx) / dt),
				})
			}
		}
		rxDatasets.push({ label: name, data: rxRates, borderColor: color, backgroundColor: color + '33' })
		txDatasets.push({ label: name, data: txRates, borderColor: color, backgroundColor: color + '33' })
		ci++
	}
	return { rxDatasets, txDatasets, sampleCount: rows.length }
}

export function buildSystemNetRates(data) {
	const netRx = []
	const netTx = []
	for (let i = 1; i < data.length; i++) {
		const dt = (new Date(data[i].ts) - new Date(data[i - 1].ts)) / 1000
		if (dt > 0) {
			netRx.push({
				x: new Date(data[i].ts),
				y: Math.max(0, (data[i].net_rx_bytes - data[i - 1].net_rx_bytes) / dt),
			})
			netTx.push({
				x: new Date(data[i].ts),
				y: Math.max(0, (data[i].net_tx_bytes - data[i - 1].net_tx_bytes) / dt),
			})
		}
	}
	return { netRx, netTx }
}
