import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
const ncGcsVue = path.resolve(root, '..', 'nc-gcs', 'apps', 'nc_gcs', 'node_modules', 'vue')
const localVue = path.join(root, 'node_modules', 'vue')
if (fs.existsSync(ncGcsVue) && fs.existsSync(path.join(root, 'node_modules'))) {
	try {
		if (fs.lstatSync(localVue).isDirectory()) {
			fs.rmSync(localVue, { recursive: true, force: true })
		}
		fs.symlinkSync(ncGcsVue, localVue, 'dir')
		console.log('[dedupe-vue] linked vue → nc_gcs')
	} catch (e) {
		console.warn('[dedupe-vue]', e.message)
	}
}
