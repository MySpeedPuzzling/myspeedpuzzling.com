# Previous published release — its /build assets are carried into this image so
# that HTML rendered by the outgoing container keeps resolving during the
# blue-green rollout window (see the merge step below)
FROM ghcr.io/myspeedpuzzling/website:main AS previous-release

FROM ghcr.io/myspeedpuzzling/web-base-php85:main

ENV APP_ENV="prod" \
    APP_DEBUG=0 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    PHP_ZEND_ASSERTIONS=-1

# Remove Xdebug entirely from the production image (the base ships it
# disabled behind XDEBUG_MODE, but prod should not even carry the ini)
RUN rm -f $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini.disabled

COPY .docker/on-startup.sh /docker-entrypoint.d/

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --no-scripts

COPY package.json package-lock.json ./
RUN npm install

COPY webpack.config.js ./
COPY ./assets ./assets
ENV NODE_ENV=production
RUN npm run build

COPY . .

# Need to run again to trigger scripts with application code present.
# Runs before the pre-compression step because assets:install creates
# public/bundles, which does not exist in the repo.
RUN composer install --no-dev --no-interaction --classmap-authoritative

# Pre-compress static assets at maximum quality for Caddy's precompressed file_server.
# Brotli q11 is ~10-17% smaller than on-the-fly q5-6, with zero serving CPU overhead.
# Scoped to the directories Caddy actually serves with `precompressed` -
# siblings anywhere else are dead weight the file server never uses.
RUN find public/build public/bundles public/css public/fonts public/img -type f \( -name '*.js' -o -name '*.css' -o -name '*.svg' \) \
        -exec brotli -q 11 --keep {} \; \
        -exec gzip -9 --keep {} \;

# Carry the previous release's hashed build assets (incl. their precompressed
# siblings, so they are not recompressed here) so both HTML generations
# resolve during blue-green rollout - capped at one generation back
# (see .docker/merge-previous-build.php)
COPY --from=previous-release /app/public/build /tmp/previous-build
RUN php .docker/merge-previous-build.php /tmp/previous-build public/build \
        && rm -rf /tmp/previous-build

ARG APP_VERSION
ENV SENTRY_RELEASE="${APP_VERSION}"
