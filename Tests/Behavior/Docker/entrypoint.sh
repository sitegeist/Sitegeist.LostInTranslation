#!/usr/bin/env bash
#
# Bootstraps a throwaway Neos distribution into /dist (a Docker volume, so it is
# cached across runs), symlinks this package into it, applies migrations and runs
# Behat. Re-runs skip the bootstrap unless the volume is dropped (`make e2e-clean`).
#
# Any arguments are forwarded to Behat. Arguments ending in `.feature` (optionally
# with a `:line` suffix) are resolved relative to the package root, so you can run
# a single feature with:
#   make e2e-feature FEATURE=Tests/Behavior/Features/Synchronization.feature
set -euo pipefail

NEOS_VERSION="${NEOS_VERSION:-9.1}"
DIST_DIR="/dist"
PACKAGE_SRC="/package"
PACKAGE_NAME="sitegeist/lostintranslation"
PACKAGE_PATH_IN_DIST="Packages/Application/Sitegeist.LostInTranslation"
BEHAT_CONFIG="${PACKAGE_PATH_IN_DIST}/Tests/Behavior/behat.yml.dist"
SENTINEL="${DIST_DIR}/.e2e-initialized"

wait_for_db() {
    echo "› Waiting for database at ${DB_HOST}:${DB_PORT} ..."
    until php -r '
        try { new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"), getenv("DB_USER"), getenv("DB_PASSWORD")); }
        catch (Throwable $e) { exit(1); }
    ' 2>/dev/null; do
        sleep 2
    done
}

bootstrap_distribution() {
    if [ -f "$SENTINEL" ]; then
        echo "› Distribution already initialized (run 'make e2e-clean' to rebuild it)."
        return
    fi

    echo "› Creating Neos ${NEOS_VERSION} base distribution ..."
    composer create-project --no-scripts --no-install \
        neos/neos-base-distribution "$DIST_DIR" "^${NEOS_VERSION}"

    cd "$DIST_DIR"
    composer config --no-plugins allow-plugins.neos/composer-plugin true

    # The 9.1.x line of some Neos packages (e.g. neos/buildessentials) is only
    # published as dev, so a plain "stable" root would fail to resolve. Allow dev
    # while still preferring stable releases where they exist.
    composer config minimum-stability dev
    composer config prefer-stable true

    # Live symlink: composer keeps Packages/Application/Sitegeist.LostInTranslation
    # pointing at the bind-mounted source, so source edits need no reinstall.
    composer config repositories.lostintranslation \
        "{\"type\":\"path\",\"url\":\"${PACKAGE_SRC}\",\"options\":{\"symlink\":true}}"

    echo "› Requiring package + Behat dev dependencies ..."
    composer require --no-install "${PACKAGE_NAME}:@dev"
    composer require --dev --no-install \
        "neos/behat:^${NEOS_VERSION}" \
        "neos/contentrepository-testsuite:^${NEOS_VERSION}" \
        "neos/contentgraph-doctrinedbaladapter:^${NEOS_VERSION}" \
        "fakerphp/faker:^1.23.0"

    composer install --no-progress

    touch "$SENTINEL"
}

write_db_settings() {
    mkdir -p "${DIST_DIR}/Configuration/Testing"
    cat > "${DIST_DIR}/Configuration/Testing/Settings.Database.yaml" <<YAML
Neos:
  Flow:
    persistence:
      backendOptions:
        driver: pdo_mysql
        host: '${DB_HOST}'
        port: ${DB_PORT}
        user: '${DB_USER}'
        password: '${DB_PASSWORD}'
        dbname: '${DB_NAME}'
YAML
}

main() {
    wait_for_db
    bootstrap_distribution
    write_db_settings

    cd "$DIST_DIR"

    echo "› Applying migrations ..."
    FLOW_CONTEXT=Testing/Behat ./flow doctrine:migrate --quiet

    # Resolve feature paths relative to the package root; pass everything else through.
    local args=()
    for arg in "$@"; do
        case "$arg" in
            *.feature|*.feature:*) args+=("${PACKAGE_PATH_IN_DIST}/${arg}") ;;
            *) args+=("$arg") ;;
        esac
    done

    echo "› Running Behat ..."
    FLOW_CONTEXT=Testing/Behat ./bin/behat -c "$BEHAT_CONFIG" "${args[@]}"
}

main "$@"
