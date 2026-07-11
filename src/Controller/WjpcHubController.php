<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetWjpcEvents;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Evergreen SEO hub for the World Jigsaw Puzzle Championship (WJPC).
 * Static content page linking down to per-year event pages.
 */
final class WjpcHubController extends AbstractController
{
    public function __construct(
        readonly private GetWjpcEvents $getWjpcEvents,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/mistrovstvi-sveta-ve-skladani-puzzle',
            'en' => '/en/world-jigsaw-puzzle-championship',
            'es' => '/es/campeonato-mundial-de-puzzles',
            'ja' => '/ja/世界ジグソーパズル選手権',
            'fr' => '/fr/championnat-du-monde-de-puzzle',
            'de' => '/de/puzzle-weltmeisterschaft',
        ],
        name: 'wjpc_hub',
    )]
    public function __invoke(): Response
    {
        $editions = $this->getWjpcEvents->allEditions();

        return $this->render('wjpc_hub.html.twig', [
            'editions' => $editions,
            'next_edition' => $this->findNextEdition($editions),
        ]);
    }

    /**
     * @param array<CompetitionEvent> $editions Ordered newest first.
     */
    private function findNextEdition(array $editions): null|CompetitionEvent
    {
        $today = $this->clock->now()->setTime(0, 0);
        $nextEdition = null;

        foreach ($editions as $edition) {
            $startDate = $edition->dateFrom ?? $edition->dateTo;

            if ($startDate !== null && $startDate >= $today) {
                // Editions are sorted newest first, so the last match is the soonest upcoming one.
                $nextEdition = $edition;
            }
        }

        return $nextEdition;
    }
}
