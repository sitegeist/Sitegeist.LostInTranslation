# Containerized E2E (Behat) and manual-dev (SUT) tasks. Requires only Docker on the host.
#
# Run `make` or `make help` for the list of targets.
# Override defaults: PHP_VERSION=8.2 NEOS_VERSION=9.0 SUT_PORT=9000 make dev

COMPOSE := docker compose -f Tests/Behavior/Docker/docker-compose.yml
# Compose prefixes named volumes with the project name (see `name:` in the compose file).
DEV_DIST_VOLUME := lostintranslation-e2e_dist-dev

.DEFAULT_GOAL := help

.PHONY: help e2e e2e-feature e2e-shell e2e-clean e2e-rebuild \
        dev dev-shell dev-drop-projection dev-cr-setup dev-stop dev-clean

##@ General

help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage: make \033[36m<target>\033[0m\n"} \
		/^##@/ {printf "\n\033[1m%s\033[0m\n", substr($$0, 5); next} \
		/^[a-zA-Z0-9_-]+:.*##/ {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@printf "\nOverride defaults: \033[36mPHP_VERSION=8.2 NEOS_VERSION=9.0 SUT_PORT=9000 make dev\033[0m\n\n"

##@ Behat (automated tests)

e2e: ## Run the full Behat suite
	$(COMPOSE) run --rm behat

e2e-feature: ## Run one feature/scenario: FEATURE=path/to.feature [NAME="scenario title"]
	$(COMPOSE) run --rm behat $(FEATURE) $(if $(NAME),--name "$(NAME)",)

e2e-shell: ## Shell into the Behat runner (distribution at /dist)
	$(COMPOSE) run --rm --entrypoint bash behat

e2e-clean: ## Stop containers and drop the cached Behat distribution
	$(COMPOSE) down -v

e2e-rebuild: ## Rebuild the PHP image from scratch
	$(COMPOSE) build --no-cache behat

##@ Manual testing (browsable Neos with this package)

dev: ## Boot + serve Neos at http://localhost:8081/neos (admin/password)
	$(COMPOSE) up sut

dev-shell: ## Shell into the running SUT (distribution at /dist)
	$(COMPOSE) exec sut bash

# Simulate a content repository where the projection was never set up: drop its three tables. Reload the
# LostInTranslation > Synchronization module to see the "not set up" status banner.
dev-drop-projection: ## Drop the stale-translation projection tables -> module shows "not set up"
	$(COMPOSE) exec database mariadb -uroot -proot neos -e "DROP TABLE IF EXISTS cr_default_p_staletranslation_nodeaggregate_type, cr_default_p_staletranslation_ws_hierarchy, cr_default_p_staletranslation;"
	@echo "› Projection schema dropped. Reload the Synchronization module to see the 'not set up' banner."

# Restore the projection: recreate its schema and replay it back to ACTIVE so the module reports "ready" again.
dev-cr-setup: ## Recreate + replay the projection -> module shows "ready" again
	$(COMPOSE) exec sut bash -c "cd /dist && FLOW_CONTEXT=Development ./flow cr:setup && FLOW_CONTEXT=Development ./flow subscription:replay Sitegeist.LostInTranslation:StaleTranslations --force"

dev-stop: ## Stop the SUT, keeping its cached distribution
	$(COMPOSE) stop sut

dev-clean: ## Stop the SUT and drop its cached distribution
	$(COMPOSE) down -v
