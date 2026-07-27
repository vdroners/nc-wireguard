<template>
	<div class="nc-wg-peer-cards">
		<div
			v-for="c in clients"
			:key="c.id"
			class="nc-wg-peer-card card"
			:class="peerRowClass(c)">
			<div class="nc-wg-peer-card__head" @click="$emit('select', c)">
				<div>
					<strong>{{ c.name }}</strong>
					<span class="mono-xs muted">{{ c.ipv4Address }}</span>
				</div>
				<div class="nc-wg-peer-card__badges">
					<span :class="c.connected ? 'badge-on' : 'badge-off'">{{ c.connected ? 'Online' : 'Offline' }}</span>
					<span v-if="c.enabled === false" class="badge-muted">Disabled</span>
					<span v-if="isExpiringSoon(c.expiresAt)" class="badge-warn">Expiring</span>
				</div>
			</div>
			<div class="nc-wg-peer-card__stats">
				<span class="text-sky">↓ {{ fmtBytes(c.transferRx) }}</span>
				<span class="text-amber">↑ {{ fmtBytes(c.transferTx) }}</span>
				<span class="muted">{{ timeAgo(c.latestHandshakeAt) }}</span>
			</div>
			<div class="nc-wg-peer-card__actions">
				<button type="button" class="nc-wg-btn nc-wg-btn--sm" @click.stop="$emit('config', c)">Config</button>
				<button type="button" class="nc-wg-btn nc-wg-btn--sm" @click.stop="$emit('edit', c)">Edit</button>
				<button type="button" class="nc-wg-btn nc-wg-btn--sm" @click.stop="$emit('toggle', c)">
					{{ c.enabled === false ? 'Enable' : 'Disable' }}
				</button>
				<button
					type="button"
					class="nc-wg-peer-card__expand nc-wg-btn nc-wg-btn--sm"
					@click.stop="toggleExpand(c.id)">
					{{ expandedId === c.id ? 'Less' : 'More' }}
				</button>
			</div>
			<div v-if="expandedId === c.id" class="nc-wg-peer-card__detail">
				<div><span class="muted">Endpoint</span> {{ c.endpoint || '—' }}</div>
				<div><span class="muted">Enabled</span> {{ c.enabled !== false ? 'Yes' : 'No' }}</div>
				<div><span class="muted">Expires</span> {{ c.expiresAt ? fmtTime(c.expiresAt) : '—' }}</div>
				<button type="button" class="nc-wg-btn nc-wg-btn--sm nc-wg-btn--danger" @click.stop="$emit('delete', c)">
					Delete
				</button>
			</div>
		</div>
		<div v-if="!clients.length" class="nc-wg-empty">No VPN peers configured yet.</div>
	</div>
</template>

<script>
import { fmtBytes, fmtTime, timeAgo } from '../utils/format.js'
import { isExpiringSoon, peerRowClass } from '../utils/peer.js'

export default {
	name: 'PeerCardList',
	props: {
		clients: { type: Array, default: () => [] },
	},
	emits: ['select', 'config', 'edit', 'toggle', 'delete'],
	data() {
		return { expandedId: null }
	},
	methods: {
		fmtBytes,
		fmtTime,
		timeAgo,
		isExpiringSoon,
		peerRowClass,
		toggleExpand(id) {
			this.expandedId = this.expandedId === id ? null : id
		},
	},
}
</script>

<style scoped>
.nc-wg-peer-card__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 0.35rem;
	margin-top: 0.5rem;
}
</style>
