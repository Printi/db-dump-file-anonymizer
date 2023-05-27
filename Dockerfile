# Builder stage for testable image
# Installs the app and dependencies in /build/testable
FROM composer:2 AS testable-builder

WORKDIR /build/testable
COPY composer.* ./
RUN composer install --no-interaction --prefer-dist --ignore-platform-reqs --working-dir=/build/testable
COPY . ./

# Builder stage for runnable image
# Installs the app and dependencies in /build/runnable
FROM composer:2 AS runnable-builder

WORKDIR /build/runnable
COPY composer.* ./
RUN composer install --no-interaction --no-dev --prefer-dist --ignore-platform-reqs --working-dir=/build/runnable
COPY . ./
RUN rm -rf \
        phpcs.xml \
        phpunit.xml \
        sample \
        tests

# Base image
# Used as base for testable and runnable images
FROM php:8-cli-alpine AS base-image

ARG UID=1000
ARG GID=1000
ENV ENV="/etc/profile"

RUN addgroup -g $GID db-anonymizer && \
    adduser -u $UID -G db-anonymizer -D db-anonymizer && \
    echo -e "#!/bin/sh\nexport PATH=\"$PATH:/app/bin:/app/vendor/bin\"" >> /etc/profile.d/profile.sh

# Testable stage (final image)
# Runs the command "composer test" by default
FROM base-image AS testable

ENV XDEBUG_MODE="coverage"

RUN apk update && \
    apk add --no-cache nano tree && \
    apk add --no-cache --virtual .dev-exts $PHPIZE_DEPS linux-headers && \
    pecl install xdebug && \
    docker-php-ext-enable xdebug && \
    apk del --no-cache .dev-exts && \
    php -m

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=testable-builder --chown=db-anonymizer:db-anonymizer /build/testable ./
RUN mkdir -p /app/var && chown db-anonymizer:db-anonymizer /app/var

USER db-anonymizer

RUN composer check-platform-reqs --no-interaction --no-scripts --no-cache --working-dir=/app

CMD ["composer", "test"]

# Runnable stage (final image)
# Runs the entrypoint "anonymize-db-dump"
FROM base-image AS runnable

LABEL maintainer="Rubens Takiguti Ribeiro <rubens.ribeiro@printi.com.br>"

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=runnable-builder --chown=db-anonymizer:db-anonymizer /build/runnable ./

USER db-anonymizer

RUN composer check-platform-reqs --no-dev --no-interaction --no-scripts --no-cache --working-dir=/app

ENTRYPOINT ["php", "/app/bin/anonymize-db-dump"]
