FROM unit:1.31.0-php8.2

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
