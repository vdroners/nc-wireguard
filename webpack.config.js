const path = require('path')
const { merge } = require('webpack-merge')
const baseConfig = require('@nextcloud/webpack-vue-config')
const webpack = require('webpack')
const pkg = require('./package.json')

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
			'@': path.resolve(__dirname, 'src'),
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
