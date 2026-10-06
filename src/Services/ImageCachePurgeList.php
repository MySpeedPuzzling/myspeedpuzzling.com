<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

/**
 * Where the old (guessable) name of a picture may still be served after the object itself is gone: the images-cache
 * nginx keeps every thumbnail 365 days (`proxy_cache_key "$request_uri"`, `levels=1:2`), and Cloudflare caches every
 * img.* URL - thumbnails and /original/ alike - as `public, immutable`.
 *
 * OSS nginx has no per-key purge, but a cache entry is a plain file named md5(key) - deleting the file is a miss on
 * the next request. Keep PRESETS in sync with IMGPROXY_PRESETS (lily.srv apps/myspeedpuzzling/compose.yaml).
 */
readonly final class ImageCachePurgeList
{
    public const array PRESETS = ['puzzle_small', 'puzzle_medium', 'avatar', 'puzzle_large'];

    public const string NGINX_CACHE_DIRECTORY = '/var/cache/nginx/imgproxy';

    public function __construct(
        private string $nginxProxyBaseUrl,
    ) {
    }

    /**
     * Files to delete in the images-cache container (one per thumbnail preset).
     *
     * @param list<string> $paths
     * @return list<string>
     */
    public function nginxCacheFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            foreach (self::PRESETS as $preset) {
                $hash = md5(sprintf('/preset:%s/plain/%s', $preset, ltrim($path, '/')));
                // levels=1:2 - the last character, then the two before it
                $files[] = sprintf('%s/%s/%s/%s', self::NGINX_CACHE_DIRECTORY, substr($hash, -1), substr($hash, -3, 2), $hash);
            }
        }

        return $files;
    }

    /**
     * URLs to purge in Cloudflare: every thumbnail, the original and its legacy /puzzle/ address.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    public function publicUrls(array $paths): array
    {
        $urls = [];

        foreach ($paths as $path) {
            $key = ltrim($path, '/');

            foreach (self::PRESETS as $preset) {
                $urls[] = sprintf('%s/preset:%s/plain/%s', $this->nginxProxyBaseUrl, $preset, $key);
            }

            $urls[] = sprintf('%s/original/%s', $this->nginxProxyBaseUrl, $key);
            $urls[] = sprintf('%s/puzzle/%s', $this->nginxProxyBaseUrl, $key);
        }

        return $urls;
    }
}
