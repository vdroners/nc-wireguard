APP_ROOT := $(dir $(abspath $(lastword $(MAKEFILE_LIST))))
NC_GCS_NM := /media/4TB/nc-gcs/apps/nc_gcs/node_modules
export PATH := $(NC_GCS_NM)/.bin:$(PATH)

.PHONY: build sync-theme deploy-docker health gate-local bump-patch bump-minor bump-major lint test

build: sync-theme
	cd $(APP_ROOT) && npm run build

sync-theme:
	bash $(APP_ROOT)/scripts/sync-theme-from-nc-gcs.sh

deploy-docker: build
	bash $(APP_ROOT)/scripts/deploy-docker.sh

health:
	docker exec cloud_app php occ nc_wireguard:poll-metrics --no-lock || docker exec cloud_app php occ nc_wireguard:poll-metrics
	docker exec cloud_app php /var/www/html/custom_apps/nc_wireguard/scripts/smoke-native.php

gate-local:
	bash $(APP_ROOT)/scripts/gate-local.sh

lint:
	cd $(APP_ROOT) && npm run lint

test:
	cd $(APP_ROOT) && composer install --no-interaction && vendor/bin/phpunit -c phpunit.xml.dist

bump-patch:
	@./scripts/bump-version.sh patch

bump-minor:
	@./scripts/bump-version.sh minor

bump-major:
	@./scripts/bump-version.sh major
