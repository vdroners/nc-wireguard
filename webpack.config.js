const path = require('path')
const { merge } = require('webpack-merge')
const baseConfig = require('@nextcloud/webpack-vue-config')
const webpack = require('webpack')
const pkg = require('./package.json')

const ncGcsSrc = resolveNcGcsSrcMirror(__dirname)
const ncGcsNm = path.resolve(__dirname, '..', 'nc-gcs', 'apps', 'nc_gcs', 'node_modules')

function resolveNcGcsSrcMirror(appRootDir) {
	const fs = require('fs')
	const mirror = path.join(appRootDir, 'src', '_nc_gcs_src_mirror')
	if (fs.existsSync(path.join(mirror, 'router.js'))) {
		return mirror
	}
	return path.resolve(appRootDir, '..', 'nc-gcs', 'apps', 'nc_gcs', 'src')
}

module.exports = merge(baseConfig, {
	entry: {
		main: path.resolve(__dirname, 'src', 'main.js'),
		admin: path.resolve(__dirname, 'src', 'admin.js'),
	},
	output: {
		publicPath: 'auto',
	},
	resolve: {
		alias: {
			'@': ncGcsSrc,
			pinia: path.join(ncGcsNm, 'pinia'),
		},
	},
	plugins: [
		new webpack.DefinePlugin({
			__NC_WIREGUARD_VERSION__: JSON.stringify(pkg.version || '0.0.0'),
		}),
	],
	module: {
		rules: [
			{
				test: /\.(png|jpe?g|gif|svg|woff2?|eot|ttf|otf)$/i,
				type: 'asset/resource',
				generator: {
					filename: 'img/[name].[contenthash:8][ext]',
				},
			},
		],
	},
})
