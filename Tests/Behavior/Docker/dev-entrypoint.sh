#!/usr/bin/env bash
#
# Boots this package inside a throwaway Neos distribution and SERVES it for manual
# testing of the LostInTranslation backend module. Unlike entrypoint.sh (which runs
# Behat), this runs in the Development context, sets up a real content repository and
# an admin user, optionally imports the Neos.Demo site, and leaves a web server
# running on $SUT_PORT.
#
# The distribution is cached in the `dist-dev` volume; `make dev-clean` drops it. The
# one-time Neos setup (admin user + demo site) is guarded by a sentinel so reboots are
# fast; the cheap, idempotent steps (migrate, cr:setup) run on every boot.
set -euo pipefail

NEOS_VERSION="${NEOS_VERSION:-9.1}"
DIST_DIR="/dist"
PACKAGE_SRC="/package"
PACKAGE_NAME="sitegeist/lostintranslation"
PACKAGE_PATH_IN_DIST="Packages/Application/Sitegeist.LostInTranslation"
BOOTSTRAP_SENTINEL="${DIST_DIR}/.dev-bootstrapped"
SETUP_SENTINEL="${DIST_DIR}/.dev-setup-done"
SERVER_HOST="0.0.0.0"
SERVER_PORT="${SUT_PORT:-8081}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-password}"

export FLOW_CONTEXT=Development

wait_for_db() {
    echo "› Waiting for database at ${DB_HOST}:${DB_PORT} ..."
    until php -r '
        try { new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"), getenv("DB_USER"), getenv("DB_PASSWORD")); }
        catch (Throwable $e) { exit(1); }
    ' 2>/dev/null; do
        sleep 2
    done
}

ensure_database() {
    # The compose `database` service only auto-creates flow_functional_testing (for Behat); the manual SUT uses its own
    # database so a Behat run cannot wipe the site you are testing.
    php -r '
        $pdo = new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"), getenv("DB_USER"), getenv("DB_PASSWORD"));
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `".getenv("DB_NAME")."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    '
}

bootstrap_distribution() {
    if [ -f "$BOOTSTRAP_SENTINEL" ]; then
        echo "› Distribution already bootstrapped (run 'make dev-clean' to rebuild it)."
        return
    fi

    echo "› Creating Neos ${NEOS_VERSION} base distribution ..."
    composer create-project --no-scripts --no-install \
        neos/neos-base-distribution "$DIST_DIR" "^${NEOS_VERSION}"

    cd "$DIST_DIR"
    composer config --no-plugins allow-plugins.neos/composer-plugin true

    # Live symlink: composer keeps Packages/Application/Sitegeist.LostInTranslation
    # pointing at the bind-mounted source, so source edits need no reinstall.
    composer config repositories.lostintranslation \
        "{\"type\":\"path\",\"url\":\"${PACKAGE_SRC}\",\"options\":{\"symlink\":true}}"

    echo "› Requiring package ..."
    composer require --no-install "${PACKAGE_NAME}:@dev"
    composer install --no-progress

    touch "$BOOTSTRAP_SENTINEL"
}

write_db_settings() {
    mkdir -p "${DIST_DIR}/Configuration/Development"
    cat > "${DIST_DIR}/Configuration/Development/Settings.Database.yaml" <<YAML
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

write_example_configuration() {
    # Merged by Flow alongside the distribution's own configuration; re-copied every boot so edits to the committed
    # files take effect on restart.
    #  - Settings: example synchronization rules + dimension reference languages (own Settings*.yaml, never clobbered).
    #  - Objects (Development context): bind the translation service to the dummy so "Sync now" needs no DeepL key.
    cp "${PACKAGE_SRC}/Tests/Behavior/Docker/dev-settings.yaml" \
        "${DIST_DIR}/Configuration/Settings.LostInTranslation.yaml"
    cp "${PACKAGE_SRC}/Tests/Behavior/Docker/dev-objects.yaml" \
        "${DIST_DIR}/Configuration/Development/Objects.yaml"
}

setup_neos() {
    cd "$DIST_DIR"

    echo "› Applying migrations ..."
    ./flow doctrine:migrate --quiet

    echo "› Setting up the content repository ..."
    ./flow cr:setup

    if [ -f "$SETUP_SENTINEL" ]; then
        return
    fi

    echo "› Creating admin user '${ADMIN_USER}' ..."
    ./flow user:create --username "$ADMIN_USER" --password "$ADMIN_PASSWORD" \
        --first-name Admin --last-name User --roles Administrator || true

    # Best-effort: a demo site makes the synchronization overview more interesting, but the LostInTranslation module is
    # reachable without one, so never let import failure block the server.
    echo "› Importing the Neos.Demo site (best effort) ..."
    ./flow site:importAll --package-key Neos.Demo \
        || ./flow site:import --package-key Neos.Demo \
        || echo "› No site imported — import one yourself via 'make dev-shell'."

    touch "$SETUP_SENTINEL"
}

main() {
    wait_for_db
    ensure_database
    bootstrap_distribution
    write_db_settings
    write_example_configuration
    setup_neos

    cd "$DIST_DIR"
    echo ""
    echo "════════════════════════════════════════════════════════════════════"
    echo " Neos backend:  http://localhost:${SERVER_PORT}/neos"
    echo " Login:         ${ADMIN_USER} / ${ADMIN_PASSWORD}"
    echo " LIT module:    http://localhost:${SERVER_PORT}/neos/management/sitegeist_lostintranslation"
    echo "════════════════════════════════════════════════════════════════════"
    echo ""
    exec ./flow server:run --host "$SERVER_HOST" --port "$SERVER_PORT"
}

main "$@"
