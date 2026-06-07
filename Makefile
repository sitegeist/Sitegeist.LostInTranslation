# Containerized E2E (Behat) tasks. Requires only Docker on the host.
#
#   make e2e                 # run the full Behat suite
#   make e2e-feature FEATURE=Tests/Behavior/Features/Synchronization.feature
#   make e2e-feature FEATURE=Tests/Behavior/Features/Synchronization.feature NAME="some scenario"
#   make e2e-shell           # shell into the runner (distribution at /dist)
#   make e2e-clean           # stop containers and drop the cached distribution
#   make e2e-rebuild         # rebuild the PHP image from scratch
#
# Override versions: PHP_VERSION=8.2 NEOS_VERSION=9.0 make e2e

COMPOSE := docker compose -f Tests/Behavior/Docker/docker-compose.yml

.PHONY: e2e e2e-feature e2e-shell e2e-clean e2e-rebuild

e2e:
	$(COMPOSE) run --rm behat

e2e-feature:
	$(COMPOSE) run --rm behat $(FEATURE) $(if $(NAME),--name "$(NAME)",)

e2e-shell:
	$(COMPOSE) run --rm --entrypoint bash behat

e2e-clean:
	$(COMPOSE) down -v

e2e-rebuild:
	$(COMPOSE) build --no-cache behat
