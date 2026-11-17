<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use SpeedPuzzling\Web\Results\CompetitionDisplayState;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The QR codes of a display's `qr` elements (D-displays-overlays.md §5, competition toolkit D4): for every shown
 * element, the code's modules - `{size, rows}`, one string of `0`/`1` per row - which the renderer draws as one SVG
 * path in the element's own colours (assets/displays/render.js). Lighter than an SVG data URI per element and
 * recolourable; the renderer only ever reads digits from it.
 *
 * Targets: the event page (`event_detail` / `edition_detail`), the shown round's public results page, or the
 * organiser's own `https://` link (validated by the design sanitizer). All in the display's language, absolute. No
 * address (an event or round without a slug) = no code. A URL never changes its code, so the modules are cached per URL.
 */
final readonly class DisplayQrCodes
{
    private const int CACHE_SECONDS = 90 * 24 * 3600;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private CacheInterface $nameTagQrCache,
    ) {
    }

    /**
     * @return array<string, array{size: int, rows: list<string>}> by element id
     */
    public function forState(CompetitionDisplayState $state): array
    {
        $elements = is_array($state->design['elements'] ?? null) ? $state->design['elements'] : [];
        $codes = [];

        foreach ($elements as $element) {
            if (!is_array($element) || ($element['type'] ?? null) !== 'qr' || ($element['hidden'] ?? false) === true) {
                continue;
            }

            $phases = is_array($element['when'] ?? null) ? ($element['when']['phases'] ?? []) : [];
            $id = $element['id'] ?? null;

            if (!is_string($id) || !is_array($phases) || $phases === []) {
                continue;
            }

            $url = $this->url(is_array($element['props'] ?? null) ? $element['props'] : [], $state);

            if ($url !== null) {
                $codes[$id] = $this->modules($url);
            }
        }

        return $codes;
    }

    /**
     * @param array<mixed> $props
     */
    private function url(array $props, CompetitionDisplayState $state): null|string
    {
        $target = $props['target'] ?? 'event_page';

        if ($target === 'custom') {
            $url = $props['url'] ?? null;

            return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
        }

        $event = $state->event;

        if ($event === null) {
            return null;
        }

        $route = $event->routeName();

        if ($target === 'event_page') {
            return $route !== null ? $this->generate($route, $event->routeParameters(), $state->locale) : null;
        }

        if ($target === 'round_results' && $state->roundSlug !== null && $state->roundSlug !== '') {
            return match ($route) {
                'event_detail' => $this->generate('event_round_results', [...$event->routeParameters(), 'roundSlug' => $state->roundSlug], $state->locale),
                'edition_detail' => $this->generate('edition_round_results', [...$event->routeParameters(), 'roundSlug' => $state->roundSlug], $state->locale),
                default => null,
            };
        }

        return null;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function generate(string $route, array $parameters, string $locale): string
    {
        return $this->urlGenerator->generate($route, [...$parameters, '_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * @return array{size: int, rows: list<string>}
     */
    public function modules(string $url): array
    {
        return $this->nameTagQrCache->get('display_qr_' . sha1($url), static function (ItemInterface $item) use ($url): array {
            $item->expiresAfter(self::CACHE_SECONDS);

            $matrix = Encoder::encode($url, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
            $size = $matrix->getWidth();
            $rows = [];

            for ($y = 0; $y < $size; $y++) {
                $row = '';

                for ($x = 0; $x < $size; $x++) {
                    $row .= $matrix->get($x, $y) === 1 ? '1' : '0';
                }

                $rows[] = $row;
            }

            return ['size' => $size, 'rows' => $rows];
        });
    }
}
