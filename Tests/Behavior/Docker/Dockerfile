# Behat / E2E runner image for Sitegeist.LostInTranslation.
#
# Contains PHP + Composer + the native extensions Neos needs. The actual Neos
# distribution is NOT baked in — it is bootstrapped at first run into the `dist`
# volume by entrypoint.sh, with this package symlinked into Packages/Application.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

# System libraries required to build the PHP extensions below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libonig-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" intl mbstring pdo_mysql zip gd exif \
    && rm -rf /var/lib/apt/lists/*

# Composer 2.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# CLI tuning required by Flow's Testing/Behat run (see TESTING.md):
#  - generous memory_limit: Flow's compile-time subprocess OOMs on the 128M default
#  - silence E_DEPRECATED: the Testing/Behat error handler otherwise rethrows
#    deprecations from vendored Neos code as fatal configuration errors
RUN { \
        echo 'memory_limit=2G'; \
        echo 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED'; \
    } > /usr/local/etc/php/conf.d/zz-neos-behat.ini

ENV COMPOSER_MEMORY_LIMIT=-1 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

WORKDIR /dist

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
