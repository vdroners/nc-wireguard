APP_ID := nc_wireguard
ROOT := $(dir $(abspath $(lastword $(MAKEFILE_LIST))))
VERSION := $(shell grep -oE '<version>[0-9]+\.[0-9]+\.[0-9]+</version>' "$(ROOT)appinfo/info.xml" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')
STAGING := /tmp/$(APP_ID)-$(VERSION)
TARBALL := /tmp/$(APP_ID)-$(VERSION).tar.gz
CONTAINER ?= cloud_app
REMOTE := /var/www/html/custom_apps/$(APP_ID)

.PHONY: build deploy-docker health gate-local lint test appstore appstore-sign bump-patch bump-minor bump-major

build:
	cd $(ROOT) && npm run build

deploy-docker: build
	bash $(ROOT)/scripts/deploy-docker.sh

health:
	docker exec cloud_app php occ nc_wireguard:poll-metrics --no-lock || docker exec cloud_app php occ nc_wireguard:poll-metrics
	docker exec cloud_app php /var/www/html/custom_apps/nc_wireguard/scripts/smoke-native.php

gate-local:
	bash $(ROOT)/scripts/gate-local.sh

lint:
	cd $(ROOT) && npm run lint

# PHP is not installed on the GCS host, so fall back to the composer image.
test:
	cd $(ROOT) && if command -v composer >/dev/null 2>&1; then \
		composer install --no-interaction && vendor/bin/phpunit -c phpunit.xml.dist; \
	else \
		echo "composer not on PATH — running in the composer:2 container"; \
		docker run --rm -v "$(ROOT):/app" -w /app composer:2 sh -c \
			'composer install --no-interaction && vendor/bin/phpunit -c phpunit.xml.dist'; \
	fi

# Assemble a self-contained release directory (built js/css + vendor, no node_modules).
appstore: build
	rm -rf "$(STAGING)"
	mkdir -p "$(STAGING)"
	rsync -a --delete \
		--exclude node_modules --exclude tests --exclude .git --exclude .backups \
		--exclude .phpunit.cache --exclude .phpunit.result.cache \
		"$(ROOT)" "$(STAGING)/"
	cd "$(STAGING)" && composer install --no-dev --no-interaction --optimize-autoloader
	rm -rf "$(STAGING)/node_modules"
	tar -czf "$(TARBALL)" -C /tmp "$(APP_ID)-$(VERSION)"
	@echo "Release tarball: $(TARBALL)"

# Sign with Nextcloud occ (set NC_OCC and signing cert env vars — see docs/APPSTORE_ONBOARDING.md).
appstore-sign: appstore
	@test -n "$(NC_OCC)" || (echo "Set NC_OCC to your occ binary path" && exit 1)
	@test -n "$$APP_PRIVATE_KEY" || (echo "Set APP_PRIVATE_KEY to private key file path" && exit 1)
	@test -n "$$APP_PUBLIC_CRT" || (echo "Set APP_PUBLIC_CRT to certificate file path" && exit 1)
	cp "$(ROOT)scripts/file_from_env.php" "$(STAGING)/file_from_env.php"
	php "$(NC_OCC)" integrity:sign-app \
		--privateKey="file://$(STAGING)/file_from_env.php" \
		--certificate="file://$(STAGING)/file_from_env.php" \
		$(APP_ID)
	APP_PRIVATE_KEY="$$APP_PRIVATE_KEY" APP_PUBLIC_CRT="$$APP_PUBLIC_CRT" \
	php "$(NC_OCC)" integrity:check-app $(APP_ID)
	tar -czf "$(TARBALL)" -C /tmp "$(APP_ID)-$(VERSION)"
	@echo "Signed tarball: $(TARBALL)"

bump-patch:
	@./scripts/bump-version.sh patch

bump-minor:
	@./scripts/bump-version.sh minor

bump-major:
	@./scripts/bump-version.sh major
