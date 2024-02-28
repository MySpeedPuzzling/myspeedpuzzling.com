FROM ghcr.io/myspeedpuzzling/web-base:main

ENV APP_ENV="prod" \
    APP_DEBUG=0 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

RUN rm $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini

COPY .docker/on-startup.sh /docker-entrypoint.d/

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --no-scripts

COPY package.json package-lock.json webpack.config.js ./
RUN npm install

COPY ./assets ./assets
RUN npm run build

COPY . .

# Need to run again to trigger scripts with application code present
RUN composer install --no-dev --no-interaction --classmap-authoritative

ARG APP_VERSION
ENV SENTRY_RELEASE="${APP_VERSION}"
