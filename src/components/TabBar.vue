<template>
	<div class="nc-wg-tabs" role="tablist">
		<template v-if="!mobileLayout">
			<button
				v-for="t in tabs"
				:key="t.id"
				type="button"
				class="nc-wg-tab-btn"
				:class="{ active: activeTab === t.id }"
				role="tab"
				:aria-selected="activeTab === t.id"
				@click="$emit('change', t.id)">
				{{ t.label }}<span v-if="t.badge != null" class="nc-wg-tab-badge">{{ t.badge }}</span>
			</button>
		</template>
		<template v-else>
			<button
				type="button"
				class="nc-wg-tab-btn"
				:class="{ active: activeTab === 'overview' }"
				role="tab"
				:aria-selected="activeTab === 'overview'"
				@click="$emit('change', 'overview')">
				Overview<span v-if="overviewBadge != null" class="nc-wg-tab-badge">{{ overviewBadge }}</span>
			</button>
			<div class="nc-wg-tab-more">
				<label class="nc-wg-tab-more__label" for="nc-wg-more-select">More</label>
				<select
					id="nc-wg-more-select"
					class="nc-wg-tab-more__select"
					:value="moreTabValue"
					@change="onMoreChange">
					<option value="" disabled>Select tab…</option>
					<option v-for="t in moreTabs" :key="t.id" :value="t.id">
						{{ t.label }}{{ t.badge != null ? ' (' + t.badge + ')' : '' }}
					</option>
				</select>
			</div>
		</template>
	</div>
</template>

<script>
const MORE_IDS = ['bandwidth', 'connections', 'map', 'system']

export default {
	name: 'TabBar',
	props: {
		tabs: { type: Array, required: true },
		activeTab: { type: String, required: true },
		mobileLayout: { type: Boolean, default: false },
		overviewBadge: { type: [Number, String], default: null },
	},
	computed: {
		moreTabs() {
			return this.tabs.filter(t => MORE_IDS.includes(t.id))
		},
		moreTabValue() {
			return MORE_IDS.includes(this.activeTab) ? this.activeTab : ''
		},
	},
	methods: {
		onMoreChange(e) {
			const id = e.target.value
			if (id) {
				this.$emit('change', id)
			}
		},
	},
}
</script>
