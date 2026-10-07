<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The QR on a printed name tag (docs/features/competitions-management/live-results.md): the absolute URL of
 * `live_results_scan` for the participant, as inline SVG - a sheet of 200 tags makes no extra request. An organiser's
 * phone camera opens the live entry with the participant; the live entry's own scanner reads the ids from it.
 *
 * Drawing one takes ~15 ms (a version 7 code: the ~110 characters of the URL), so the SVG is cached per URL - a URL
 * never changes its code. The URL keeps the route's shape (`/{_locale}/live/{competitionId}/p/{participantId}`):
 * printed tags must keep working, and the in-page scanner checks the event's id in it.
 */
final readonly class NameTagQrCode
{
    private const int SIZE = 240;
    private const int MARGIN = 2;

    private const int CACHE_SECONDS = 90 * 24 * 3600;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private CacheInterface $nameTagQrCache,
    ) {
    }

    public function url(string $competitionId, string $participantId, string $locale): string
    {
        return $this->urlGenerator->generate('live_results_scan', [
            '_locale' => $locale,
            'competitionId' => $competitionId,
            'participantId' => $participantId,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * An <svg> element (no XML declaration) for embedding in the page.
     */
    public function svg(string $url): string
    {
        return $this->nameTagQrCache->get('name_tag_qr_' . sha1($url), static function (ItemInterface $item) use ($url): string {
            $item->expiresAfter(self::CACHE_SECONDS);

            return self::draw($url);
        });
    }

    private static function draw(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(self::SIZE, self::MARGIN), new SvgImageBackEnd()));
        $svg = $writer->writeString($url, 'UTF-8', ErrorCorrectionLevel::M());

        return trim((string) preg_replace('/^<\?xml[^>]*\?>/', '', $svg));
    }
}
