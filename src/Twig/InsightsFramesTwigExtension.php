<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\Insights\InsightsCriteria;
use SpeedPuzzling\Web\Value\InsightsLazyPart;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `insights_frame('rating', criteria)` for the Insights page's lazy placeholders (templates/insights/_lazy_*.html.twig):
 * the Turbo Frame's id and src from InsightsLazyPart - the frame controllers answer with the same id.
 */
final class InsightsFramesTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('insights_frame', $this->frame(...)),
        ];
    }

    /**
     * @return array{id: string, src: string, anchors: list<string>}
     */
    public function frame(string $part, InsightsCriteria $criteria): array
    {
        $lazyPart = InsightsLazyPart::from($part);

        return [
            'id' => $lazyPart->frameId($criteria),
            'src' => $this->urlGenerator->generate($lazyPart->route(), $lazyPart->query($criteria)),
            'anchors' => $lazyPart->anchors(),
        ];
    }
}
