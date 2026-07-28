<script>
/** Self-contained app shell (no NC-GCS dependency). */
const BANNER_ICONS = {
	nc_wireguard:
		'<path d="M12 2.625c-3.728 0-6.75 3.022-6.75 6.75v3c0 3.728 3.022 6.75 6.75 6.75s6.75-3.022 6.75-6.75v-3c0-3.728-3.022-6.75-6.75-6.75zm0 2.25c2.485 0 4.5 2.015 4.5 4.5v3c0 2.485-2.015 4.5-4.5 4.5s-4.5-2.015-4.5-4.5v-3c0-2.485 2.015-4.5 4.5-4.5z" fill="currentColor" opacity="0.95"/>'
		+ '<circle cx="12" cy="12" r="2.25" fill="currentColor"/>',
}

export default {
	name: 'NcAppShell',
	props: {
		appId: { type: String, required: true },
		title: { type: String, required: true },
		subtitle: { type: String, default: '' },
		accent: { type: String, default: '' },
		hideBanner: { type: Boolean, default: false },
	},
	data() {
		return {
			shellThemeClass: '',
			shellThemeAttr: 'dark',
		}
	},
	computed: {
		shellStyle() {
			return this.accent ? { '--nc-app-accent': this.accent } : null
		},
		bannerIconLetter() {
			return (this.title || this.appId || '?').trim().charAt(0).toUpperCase()
		},
		bannerIconSvg() {
			return BANNER_ICONS[this.appId] || ''
		},
	},
	mounted() {
		this._syncShellTheme()
		this._themeObserver = new MutationObserver(() => this._syncShellTheme())
		this._themeObserver.observe(document.body, {
			attributes: true,
			attributeFilter: ['class', 'data-theme'],
		})
	},
	beforeDestroy() {
		this._themeObserver?.disconnect()
	},
	methods: {
		_syncShellTheme() {
			const body = document.body
			const isLight = body.classList.contains('theme--light')
				|| body.getAttribute('data-theme') === 'light'
			this.shellThemeClass = isLight ? 'theme--light' : ''
			this.shellThemeAttr = isLight ? 'light' : 'dark'
		},
	},
}
</script>

<template>
	<div
		class="nc-wg-app-shell"
		:class="[`nc-wg-app-shell--${appId}`, shellThemeClass]"
		:data-app-id="appId"
		:data-nc-wg-theme="shellThemeAttr"
		:data-theme="shellThemeAttr"
		:style="shellStyle">
		<header v-if="!hideBanner" class="nc-wg-app-shell__banner">
			<span class="nc-wg-app-shell__banner-icon" aria-hidden="true">
				<slot name="banner-icon">
					<svg
						v-if="bannerIconSvg"
						class="nc-wg-app-shell__banner-icon-svg"
						viewBox="0 0 24 24"
						width="22"
						height="22"
						aria-hidden="true"
						focusable="false"
						v-html="bannerIconSvg" />
					<template v-else>{{ bannerIconLetter }}</template>
				</slot>
			</span>
			<h1 class="nc-wg-app-shell__banner-title">{{ title }}</h1>
			<span v-if="subtitle || $slots['banner-extra']" class="nc-wg-app-shell__banner-subtitle">
				<slot name="banner-extra">{{ subtitle }}</slot>
			</span>
		</header>
		<div class="nc-wg-app-shell__body">
			<slot />
		</div>
		<footer v-if="$slots.footer" class="nc-wg-app-shell__footer">
			<slot name="footer" />
		</footer>
	</div>
</template>
