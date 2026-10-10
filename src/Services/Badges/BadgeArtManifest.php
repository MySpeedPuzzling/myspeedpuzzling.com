<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Badges;

use JsonException;
use SpeedPuzzling\Web\Exceptions\BadgeArtMissing;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The index of the achievement badge art, `public/img/badges/manifest.json`, written by tools/badge-art/build.py
 * together with the pictures: the version every badge URL carries (`?v=`, a hash of the art - a rebuilt badge gets a
 * new URL, so browsers and the service worker never keep an old one) and the files that exist. A badge, format or
 * size that is not in it is a programming error: BadgeArtMissing.
 *
 * Read once per worker and then kept: the manifest is deploy-static - it changes only with a deploy, and a deploy
 * starts new containers and with them new workers. It holds nothing about a visitor or a request, so keeping it across
 * the requests of a FrankenPHP worker cannot leak anything (no ResetInterface on purpose). In debug the file is read
 * again when it changes on disk, so a local rebuild of the art shows without restarting the workers.
 */
final class BadgeArtManifest
{
    public const array FORMATS = ['webp', 'png', 'svg'];

    public const string SINGLE_TIER_VARIANT = 'single';

    /**
     * @var null|array{version: string, files: array<string, true>, sizes: array<string, list<int>>}
     */
    private null|array $manifest = null;

    private null|int $manifestModifiedAt = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/img/badges/manifest.json')]
        readonly private string $manifestPath,
        #[Autowire('%kernel.debug%')]
        readonly private bool $debug,
    ) {
    }

    /**
     * `t1`-`t5` for a tier, `single` for a single-tier achievement (no tier) - the file name suffix of the art.
     */
    public static function variant(null|BadgeTier $tier): string
    {
        return $tier === null ? self::SINGLE_TIER_VARIANT : 't' . $tier->value;
    }

    /**
     * @throws BadgeArtMissing
     */
    public function version(): string
    {
        return $this->load()['version'];
    }

    /**
     * Every file of the art, relative to public/img/badges/.
     *
     * @return list<string>
     * @throws BadgeArtMissing
     */
    public function files(): array
    {
        return array_keys($this->load()['files']);
    }

    /**
     * Pixel sizes the raster format is rendered in, smallest first.
     *
     * @return non-empty-list<int>
     * @throws BadgeArtMissing
     */
    public function sizes(string $format): array
    {
        $sizes = $this->load()['sizes'][$format] ?? [];

        if ($sizes === []) {
            throw BadgeArtMissing::unreadableManifest($this->manifestPath, sprintf('no %s art', $format));
        }

        return $sizes;
    }

    /**
     * The art of one achievement and tier, relative to public/img/badges/: `svg/<type>-<variant>.svg`, the rasters in
     * `<format>/<px>/<type>-<variant>.<format>` (the SVG ignores $px).
     *
     * @throws BadgeArtMissing
     */
    public function file(BadgeType $type, string $variant, string $format, int $px): string
    {
        if (in_array($format, self::FORMATS, true) === false) {
            throw BadgeArtMissing::unknownFormat($format);
        }

        $file = $format === 'svg'
            ? sprintf('svg/%s-%s.svg', $type->value, $variant)
            : sprintf('%s/%d/%s-%s.%s', $format, $px, $type->value, $variant, $format);

        if (isset($this->load()['files'][$file]) === false) {
            throw BadgeArtMissing::notInManifest($file, $this->manifestPath);
        }

        return $file;
    }

    /**
     * @return array{version: string, files: array<string, true>, sizes: array<string, list<int>>}
     * @throws BadgeArtMissing
     */
    private function load(): array
    {
        if ($this->manifest !== null && $this->debug === false) {
            return $this->manifest;
        }

        clearstatcache(true, $this->manifestPath);
        $modifiedAt = is_file($this->manifestPath) ? filemtime($this->manifestPath) : false;

        if ($modifiedAt === false) {
            throw BadgeArtMissing::unreadableManifest($this->manifestPath, 'the file does not exist');
        }

        if ($this->manifest !== null && $modifiedAt === $this->manifestModifiedAt) {
            return $this->manifest;
        }

        $this->manifest = $this->parse((string) file_get_contents($this->manifestPath));
        $this->manifestModifiedAt = $modifiedAt;

        return $this->manifest;
    }

    /**
     * @return array{version: string, files: array<string, true>, sizes: array<string, list<int>>}
     * @throws BadgeArtMissing
     */
    private function parse(string $json): array
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw BadgeArtMissing::unreadableManifest($this->manifestPath, 'not JSON: ' . $exception->getMessage());
        }

        if (is_array($data) === false || is_string($data['version'] ?? null) === false || $data['version'] === '') {
            throw BadgeArtMissing::unreadableManifest($this->manifestPath, 'no "version"');
        }

        if (is_array($data['files'] ?? null) === false || $data['files'] === []) {
            throw BadgeArtMissing::unreadableManifest($this->manifestPath, 'no "files"');
        }

        $files = [];
        $sizes = [];

        foreach (array_keys($data['files']) as $path) {
            // Paths are relative to public/img/badges/ - tolerate them written from the public or the project root
            $file = (string) preg_replace('~^/?(?:public/)?(?:img/badges/)?~', '', (string) $path);
            $files[$file] = true;

            if (preg_match('~^(webp|png)/(\d+)/~', $file, $match) === 1) {
                $sizes[$match[1]][(int) $match[2]] = (int) $match[2];
            }
        }

        $sortedSizes = [];

        foreach ($sizes as $format => $formatSizes) {
            sort($formatSizes);
            $sortedSizes[$format] = $formatSizes;
        }

        return [
            'version' => $data['version'],
            'files' => $files,
            'sizes' => $sortedSizes,
        ];
    }
}
