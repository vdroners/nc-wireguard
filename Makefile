APP_ROOT := $(dir $(abspath $(lastword $(MAKEFILE_LIST))))
NC_GCS_NM := /media/4TB/nc-gcs/apps/nc_gcs/node_modules
export PATH := $(NC_GCS_NM)/.bin:$(PATH)

.PHONY: build sync-theme deploy-docker health gate-local bump-patch lint test

build: sync-theme
	cd $(APP_ROOT) && npm run build

sync-theme:
	bash $(APP_ROOT)/scripts/sync-theme-from-nc-gcs.sh

deploy-docker: build
	bash $(APP_ROOT)/scripts/deploy-docker.sh

health:
	curl -sf http://127.0.0.1:8185/api/health | jq .
	curl -sf http://127.0.0.1:8185/api/summary | jq '.connectedCount,.totalClients'

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
