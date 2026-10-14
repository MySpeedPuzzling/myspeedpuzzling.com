# The previous build's /build assets are carried into this image so that HTML
# rendered by the outgoing container keeps resolving during the blue-green
# rollout window (see the merge step below). Every main build pushes them as a
# tiny image of their own (the `build-assets` target at the bottom) - pulling
# the whole previous release image just for this folder took 30-80s per build.
ARG BUILD_ASSETS_IMAGE=ghcr.io/myspeedpuzzling/website:build-assets
FROM ${BUILD_ASSETS_IMAGE} AS previous-build-assets

# Composer's download cache, so a cold `composer install` (base image rotated,
# lock file changed) does not have to fetch ~170 dists from GitHub. Empty by
# default; the CI workflow's docker job overrides it with the Composer cache the
# test jobs keep (`--build-context composer-cache=.composer-cache`)
FROM scratch AS composer-cache

# The finish detector's files, served by us (docs/features/online-competitions/README.md §5.7 "Downloads"): MediaPipe
# tasks-vision's ESM bundle + wasm/ (the npm tarball, its sha512 the one package-lock.json pins) and the pose + gesture
# models (SHA-256), fetched by bin/fetch-online-detector.mjs - any other byte fails the build - with .br / .gz siblings
# for Caddy. A stage of its own on a pinned Node image, its only inputs the script and the licence text: the registry
# cache keeps it until a pin changes, while the app stage rebuilds with every daily base image (brotli 11 over the
# ~53 MB takes ~90 s of CPU)
FROM node:24.21.0-trixie-slim AS online-detector
COPY bin/fetch-online-detector.mjs /src/bin/
COPY .docker/online-detector/LICENSE-Apache-2.0.txt /src/.docker/online-detector/
RUN node /src/bin/fetch-online-detector.mjs --precompress /online-detector

FROM ghcr.io/myspeedpuzzling/web-base-php85:main AS app

ENV APP_ENV="prod" \
    APP_DEBUG=0 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    PHP_ZEND_ASSERTIONS=-1

# Remove Xdebug entirely from the production image (the base ships it
# disabled behind XDEBUG_MODE, but prod should not even carry the ini)
RUN rm -f $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini.disabled

COPY .docker/on-startup.sh /docker-entrypoint.d/

# The finish detector's files at a versioned path outside /build/ (README §5.7 "Downloads"), and the Caddy rule that
# serves them immutable and precompressed (the base Caddyfile imports /etc/frankenphp/Caddyfile.d/*.caddyfile). The
# page loads them from here (OnlineDetectorAssets); a new version = a new path in bin/fetch-online-detector.mjs and
# here - an env on the box can still point it elsewhere, or set it empty for the pinned CDNs
COPY .docker/online-detector/online-detector.caddyfile /etc/frankenphp/Caddyfile.d/
COPY --from=online-detector /online-detector public/online-detector
ENV ONLINE_DETECTOR_BASE_URL=/online-detector/1.1.0-pose-lite-1-gesture-1

COPY composer.json composer.lock symfony.lock ./
# Guards against GitHub throttling the dist downloads (2026-08-18: the base
# image rotates daily, every layer rebuilt, and codeload.github.com answered the
# anonymous downloads from the shared runner IP with HTTP 429):
#  - the Composer cache mounted from the `composer-cache` context - a warm cache
#    means no download at all (writes land in the mount and are discarded). This
#    is the guard that actually covers the codeload case: GitHub redirects every
#    zipball to a plain codeload URL and Composer only authenticates the
#    api.github.com leg, so a token never reaches codeload;
#  - GITHUB_TOKEN as a BuildKit secret, read into COMPOSER_AUTH for this one
#    command only (never touches a layer): lifts the anonymous 60/h API quota
#    that the metadata calls for the two vcs repositories in composer.json hit;
#  - fewer parallel connections and an outer retry with backoff for what still
#    has to be fetched - Composer 2.9 does not retry a 429 itself, and packages
#    already fetched sit in the cache mount, so a retry only re-asks for the rest.
# All optional: a plain `docker build` without context or secret still works.
RUN --mount=type=secret,id=github_token \
    --mount=type=bind,from=composer-cache,target=/tmp/composer-cache,rw \
    if [ -s /run/secrets/github_token ]; then \
        export COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"$(cat /run/secrets/github_token)\"}}"; \
    fi \
    && export COMPOSER_CACHE_DIR=/tmp/composer-cache COMPOSER_MAX_PARALLEL_HTTP=6 \
    && for attempt in 1 2 3; do \
        composer install --no-dev --no-interaction --no-scripts && break; \
        if [ "$attempt" = 3 ]; then echo "composer install failed 3 times, giving up" >&2; exit 1; fi; \
        echo "composer install failed (attempt $attempt), retrying in $((attempt * 30))s..." >&2; \
        sleep $((attempt * 30)); \
    done

COPY package.json package-lock.json ./
# package.json pins @mediapipe/tasks-vision for the finish detector, but the image serves the `online-detector` stage's
# copy of the same tarball (public/online-detector) - nothing imports it, so its 52 MB in node_modules are dropped.
# npm's download cache (~113 MB in /root/.npm) is cleaned in the same layer - nothing after the install reads it.
RUN npm install && npm cache clean --force && rm -rf node_modules/@mediapipe

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
# One compressor per CPU - file by file, this step took 10-16s of every build.
RUN find public/build public/bundles public/css public/fonts public/img -type f \( -name '*.js' -o -name '*.css' -o -name '*.svg' \) -print0 > /tmp/precompress \
    && xargs -0 -P "$(nproc)" -n 32 brotli -q 11 --keep < /tmp/precompress \
    && xargs -0 -P "$(nproc)" -n 32 gzip -9 --keep < /tmp/precompress \
    && rm /tmp/precompress

# Carry recent releases' hashed build assets (incl. their precompressed
# siblings, so they are not recompressed here) so HTML a browser loaded before
# this release still resolves its assets - retention is by AGE, not by build
# count, so a burst of deploys cannot evict a generation clients still hold
# (see .docker/merge-previous-build.php)
COPY --from=previous-build-assets /build /tmp/previous-build
RUN php .docker/merge-previous-build.php /tmp/previous-build public/build \
        && rm -rf /tmp/previous-build

ARG APP_VERSION
ENV SENTRY_RELEASE="${APP_VERSION}"

# Just this image's merged /build (own assets + the carried ones), pushed as
# ghcr.io/myspeedpuzzling/website:build-assets for the next build to carry
# forward. The last stage, so the CI builds name their target: `app` for the
# application image, `build-assets` for this one.
FROM scratch AS build-assets
COPY --from=app /app/public/build /build
