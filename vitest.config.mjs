import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { createRequire } from 'node:module'
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue2'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const require = createRequire(import.meta.url)
const pkg = require('./package.json')

export default defineConfig({
	plugins: [vue()],
	define: {
		__NC_WIREGUARD_VERSION__: JSON.stringify(pkg.version || '0.0.0'),
	},
	resolve: {
		alias: {
			'@': path.resolve(__dirname, 'src'),
			// Runtime-only: string `template:` options must not compile (W1 / fail-first).
			vue: path.resolve(__dirname, 'node_modules/vue/dist/vue.runtime.esm.js'),
		},
	},
	test: {
		environment: 'jsdom',
		include: ['tests/js/**/*.{test,spec}.js'],
		css: false,
	},
})
