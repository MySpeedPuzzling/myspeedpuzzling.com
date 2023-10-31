FROM ghcr.io/speedpuzzling/web-base:main

ENV APP_ENV="prod"
ENV APP_DEBUG=0
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

RUN rm /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

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
