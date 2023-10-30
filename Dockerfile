FROM unit:1.31.1-php8.2 as base

COPY .docker/wait-for-it.sh /usr/local/bin/wait-for-it

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Very convenient PHP extensions installer: https://github.com/mlocati/docker-php-extension-installer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN chmod +x /usr/local/bin/wait-for-it \
    && mkdir /.composer \
    && mkdir /usr/tmp \
    && apt-get update && apt-get install -y \
        git \
        zip \
        ca-certificates \
        curl \
        lsb-release \
    && install-php-extensions \
        bcmath \
        intl \
        pcntl \
        zip \
        uuid \
        pdo_pgsql \
        opcache \
        apcu \
        gd \
        xdebug

ENV COMPOSER_MEMORY_LIMIT=-1 \
    COMPOSER_HOME="/.composer" \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=1 \
    PHP_OPCACHE_MAX_ACCELERATED_FILES=15000 \
    PHP_OPCACHE_MEMORY_CONSUMPTION=192 \
    PHP_OPCACHE_MAX_WASTED_PERCENTAGE=10

WORKDIR /app

COPY .docker/nginx-unit/php.ini /usr/local/etc/php/conf.d/99-php-overrides.ini

RUN ln -sf /dev/stdout /var/log/unit.log \
    && ln -sf /dev/stdout /var/log/access.log


FROM base as composer

COPY composer.json composer.lock symfony.lock ./

RUN composer install --no-dev --no-interaction --no-scripts


FROM node:16 as js-builder

WORKDIR /build

# We need /vendor here
COPY --from=composer /app .

# Install npm packages
COPY package.json yarn.lock webpack.config.js ./
RUN yarn install

# Production yarn build
COPY ./assets ./assets

RUN yarn run build



FROM composer as prod

ENV APP_ENV="prod"
ENV APP_DEBUG=0
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

# Unload xdebug extension by deleting config
RUN rm /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

COPY .docker/nginx-unit /docker-entrypoint.d/

# Copy js build
COPY --from=js-builder /build .

# Copy application source code
COPY . .

# Need to run again to trigger scripts with application code present
RUN composer install --no-dev --no-interaction --classmap-authoritative
