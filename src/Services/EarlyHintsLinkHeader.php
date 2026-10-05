<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

/**
 * The `Link` header that preloads the `app` entry's CSS and JS (sent as 103 Early Hints by `EarlyHintsSubscriber`).
 *
 * Every entry must mirror the attributes of the tag that later uses the file: a preload whose
 * `integrity` (or `crossorigin`) differs from the `<link>`/`<script>` tag is discarded by Chrome
 * ("preloaded but not used" / "integrity mismatch") and the file is fetched a second time - under our
 * service worker (cacheFirst with `cache: 'reload'`) every new asset after a deploy was downloaded twice.
 * Production builds carry SRI hashes (`enableIntegrityHashes()` in webpack.config.js), so each entry gets
 * `; integrity="sha384-…"` from the `integrity` map of entrypoints.json; dev builds have no map and no parameter.
 * The Encore `crossorigin` option is unset (config/packages/webpack_encore.php) - if it is ever set, the entries
 * need the same `crossorigin` too.
 *
 * The header is cached in a property without `ResetInterface` on purpose: it holds no request data, and
 * entrypoints.json changes only with a deploy, which is a new container (new worker processes).
 */
final class EarlyHintsLinkHeader
{
    private null|string $header = null;

    public function __construct(
        private readonly string $entrypointsPath,
    ) {
    }

    public function get(): null|string
    {
        if ($this->header !== null) {
            return $this->header;
        }

        $content = @file_get_contents($this->entrypointsPath);

        if ($content === false) {
            return null;
        }

        /** @var array{entrypoints?: array{app?: array{css?: list<string>, js?: list<string>}}, integrity?: array<string, string>}|null $data */
        $data = json_decode($content, true);

        if (!is_array($data)) {
            return null;
        }

        $entrypoints = $data['entrypoints']['app'] ?? [];
        $integrity = $data['integrity'] ?? [];

        $links = [];

        foreach ($entrypoints['css'] ?? [] as $file) {
            $links[] = self::link($file, 'style', $integrity[$file] ?? null);
        }

        foreach ($entrypoints['js'] ?? [] as $file) {
            $links[] = self::link($file, 'script', $integrity[$file] ?? null);
        }

        if ($links === []) {
            return null;
        }

        $this->header = implode(', ', $links);

        return $this->header;
    }

    private static function link(string $file, string $as, null|string $integrity): string
    {
        $link = "<{$file}>; rel=preload; as={$as}";

        if ($integrity !== null && $integrity !== '') {
            $link .= "; integrity=\"{$integrity}\"";
        }

        return $link;
    }
}
