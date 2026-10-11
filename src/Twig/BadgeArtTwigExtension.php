<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\BadgeArtMissing;
use SpeedPuzzling\Web\Services\Badges\BadgeArtManifest;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The achievement badge pictures (F1 seal ladder, generated into public/img/badges/ by tools/badge-art/build.py):
 *
 * - `badge_art_url(type, tier, format = 'webp', px = 144, absolute = false)` - the URL of one file, with `?v=<manifest
 *   version>`; e-mails use `badge_art_url(type, tier, 'png', 96, absolute = true)` shown at 48 px
 * - `badge_art(type, tier, display_px = 72, locked = false, attrs = {})` - the `<img>`: WebP at twice the display size
 *   (`srcset` adds the 1x file when there is a smaller one), alt = achievement + tier, lazy, `badge-art` class and
 *   `badge-art-locked` for the locked look (greyscale at 45 % - CSS only, no extra files)
 * - `badge_holo(type, tier)` - the medal of the achievement detail sheet (null for single-tier achievements): the
 *   `finish` (`none` Bronze-Gold, `rim` Platinum, `masterpiece` Diamond), the `back` plate URL, the `foil` and `rim`
 *   mask URLs (Platinum and Diamond only, else null), all same origin with `?v=` like the art (CSS masks need
 *   same-origin files), the sampled `rimTone` / `edgeTone` and the back's text `field` (`x`, `y`, `r` in percent)
 *
 * `type` is a BadgeType or its value, `tier` a BadgeTier, its number, or null/0 for a single-tier achievement. A
 * picture that does not exist (unknown achievement or tier, a tier on a single-tier achievement, a size that is not
 * rendered) throws BadgeArtMissing in debug; production logs a warning and leaves the picture out - never a broken
 * image.
 */
final class BadgeArtTwigExtension extends AbstractExtension
{
    private const string PUBLIC_DIRECTORY = 'img/badges/';

    private const string ATTRIBUTE_NAME_PATTERN = '~^[a-zA-Z_:][a-zA-Z0-9_:.-]*$~';

    public function __construct(
        readonly private BadgeArtManifest $manifest,
        readonly private Packages $packages,
        readonly private UrlHelper $urlHelper,
        readonly private TranslatorInterface $translator,
        readonly private LoggerInterface $logger,
        #[Autowire('%kernel.debug%')]
        readonly private bool $debug,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('badge_art_url', $this->badgeArtUrl(...)),
            new TwigFunction('badge_art', $this->badgeArt(...), ['is_safe' => ['html']]),
            new TwigFunction('badge_holo', $this->badgeHolo(...)),
        ];
    }

    public function badgeArtUrl(
        BadgeType|string $type,
        null|BadgeTier|int $tier,
        string $format = 'webp',
        int $px = 144,
        bool $absolute = false,
    ): string {
        try {
            return $this->url($this->resolveType($type), $this->resolveTier($tier), $format, $px, $absolute);
        } catch (BadgeArtMissing $exception) {
            $this->report($exception);

            return '';
        }
    }

    /**
     * @return null|array{finish: 'none'|'rim'|'masterpiece', foil: null|string, rim: null|string, back: string, rimTone: string, edgeTone: string, field: array{x: float, y: float, r: float}}
     */
    public function badgeHolo(BadgeType|string $type, null|BadgeTier|int $tier): null|array
    {
        try {
            $badgeTier = $this->resolveTier($tier);

            if ($badgeTier === null) {
                return null;
            }

            $holo = $this->manifest->holo($this->resolveType($type), $badgeTier);

            if ($holo === null) {
                return null;
            }

            $version = '?v=' . rawurlencode($this->manifest->version());
        } catch (BadgeArtMissing $exception) {
            $this->report($exception);

            return null;
        }

        $hasFoil = $holo['foil'] !== null && $holo['rim'] !== null;

        return [
            'finish' => $hasFoil === false ? 'none' : ($badgeTier === BadgeTier::Diamond ? 'masterpiece' : 'rim'),
            'foil' => $holo['foil'] === null ? null : $this->packages->getUrl(self::PUBLIC_DIRECTORY . $holo['foil']) . $version,
            'rim' => $holo['rim'] === null ? null : $this->packages->getUrl(self::PUBLIC_DIRECTORY . $holo['rim']) . $version,
            'back' => $this->packages->getUrl(self::PUBLIC_DIRECTORY . $holo['back']) . $version,
            'rimTone' => $holo['rimTone'],
            'edgeTone' => $holo['edgeTone'],
            'field' => ['x' => $holo['field'][0], 'y' => $holo['field'][1], 'r' => $holo['field'][2]],
        ];
    }

    /**
     * @param array<mixed> $attrs extra attributes; `class` is added to the badge classes, anything else replaces the
     *                            default (`{alt: ''}` next to a label saying the same, `{loading: 'eager'}` above the fold)
     */
    public function badgeArt(
        BadgeType|string $type,
        null|BadgeTier|int $tier,
        int $displayPx = 72,
        bool $locked = false,
        array $attrs = [],
    ): string {
        if ($displayPx < 1) {
            throw new \InvalidArgumentException(sprintf('badge_art() display size must be positive, %d given.', $displayPx));
        }

        try {
            $badgeType = $this->resolveType($type);
            $badgeTier = $this->resolveTier($tier);
            $oneX = $this->rasterSize($displayPx);
            $twoX = $this->rasterSize(2 * $displayPx);
            $src = $this->url($badgeType, $badgeTier, 'webp', $twoX, false);
            $srcset = $oneX === $twoX
                ? null
                : sprintf('%s 1x, %s 2x', $this->url($badgeType, $badgeTier, 'webp', $oneX, false), $src);
        } catch (BadgeArtMissing $exception) {
            $this->report($exception);

            return '';
        }

        $class = $locked ? 'badge-art badge-art-locked' : 'badge-art';

        /** @var array<string, null|bool|string> $attributes */
        $attributes = [
            'src' => $src,
            'srcset' => $srcset,
            'width' => (string) $displayPx,
            'height' => (string) $displayPx,
            'alt' => $this->alt($badgeType, $badgeTier),
            'loading' => 'lazy',
            'decoding' => 'async',
            'class' => $class,
        ];

        foreach ($attrs as $name => $value) {
            $name = (string) $name;

            if (preg_match(self::ATTRIBUTE_NAME_PATTERN, $name) !== 1) {
                throw new \InvalidArgumentException(sprintf('badge_art() got an invalid attribute name "%s".', $name));
            }

            if ($value !== null && is_bool($value) === false) {
                if (is_scalar($value) === false && ($value instanceof \Stringable) === false) {
                    throw new \InvalidArgumentException(sprintf('badge_art() attribute "%s" must be a string, number or bool.', $name));
                }

                $value = (string) $value;
            }

            $attributes[$name] = $name === 'class' && is_string($value) ? $class . ' ' . $value : $value;
        }

        $html = '<img';

        foreach ($attributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            $html .= $value === true
                ? ' ' . $name
                : sprintf(' %s="%s"', $name, htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
        }

        return $html . '>';
    }

    /**
     * @throws BadgeArtMissing
     */
    private function url(BadgeType $type, null|BadgeTier $tier, string $format, int $px, bool $absolute): string
    {
        $file = $this->manifest->file($type, BadgeArtManifest::variant($tier), $format, $px);
        $url = $this->packages->getUrl(self::PUBLIC_DIRECTORY . $file) . '?v=' . rawurlencode($this->manifest->version());

        return $absolute ? $this->urlHelper->getAbsoluteUrl($url) : $url;
    }

    /**
     * The smallest rendered WebP at least this big, else the biggest there is.
     *
     * @throws BadgeArtMissing
     */
    private function rasterSize(int $minimumPx): int
    {
        $sizes = $this->manifest->sizes('webp');

        foreach ($sizes as $size) {
            if ($size >= $minimumPx) {
                return $size;
            }
        }

        return $sizes[array_key_last($sizes)];
    }

    /**
     * @throws BadgeArtMissing
     */
    private function resolveType(BadgeType|string $type): BadgeType
    {
        if ($type instanceof BadgeType) {
            return $type;
        }

        return BadgeType::tryFrom($type) ?? throw BadgeArtMissing::unknownType($type);
    }

    /**
     * @throws BadgeArtMissing
     */
    private function resolveTier(null|BadgeTier|int $tier): null|BadgeTier
    {
        if ($tier === null || $tier === 0) {
            return null;
        }

        if ($tier instanceof BadgeTier) {
            return $tier;
        }

        return BadgeTier::tryFrom($tier) ?? throw BadgeArtMissing::unknownTier($tier);
    }

    private function alt(BadgeType $type, null|BadgeTier $tier): string
    {
        $alt = $this->translator->trans($type->translationKey());

        return $tier === null ? $alt : $alt . ' — ' . $this->translator->trans($tier->translationKey());
    }

    /**
     * @throws BadgeArtMissing in debug
     */
    private function report(BadgeArtMissing $exception): void
    {
        if ($this->debug) {
            throw $exception;
        }

        $this->logger->warning('Badge art missing, the picture is left out: {reason}', [
            'reason' => $exception->getMessage(),
            'exception' => $exception,
        ]);
    }
}
