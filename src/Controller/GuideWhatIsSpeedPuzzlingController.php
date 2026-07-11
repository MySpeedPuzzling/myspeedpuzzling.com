<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetSolveTimeDistribution;
use SpeedPuzzling\Web\Results\SolveTimeDistribution;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "What is speed puzzling" guide. English-only by design.
 */
final class GuideWhatIsSpeedPuzzlingController extends AbstractController
{
    public function __construct(
        readonly private GetSolveTimeDistribution $getSolveTimeDistribution,
        readonly private CacheInterface $cache,
        readonly private TranslatorInterface $translator,
        #[Autowire(param: 'kernel.environment')]
        readonly private string $environment,
    ) {
    }

    #[Route(path: '/en/guides/what-is-speed-puzzling', name: 'guide_what_is_speed_puzzling', defaults: ['_locale' => 'en'])]
    public function __invoke(): Response
    {
        /** @var array<int, SolveTimeDistribution> $distributions */
        $distributions = $this->cache->get(
            sprintf('%s_%s', GuidePuzzleTimeByPiecesController::STATS_CACHE_KEY, $this->environment),
            function (ItemInterface $item): array {
                $item->expiresAfter(GuidePuzzleTimeByPiecesController::STATS_CACHE_TTL);

                return $this->getSolveTimeDistribution->byPiecesCounts(GuidePuzzleTimeByPiecesController::PIECES_BUCKETS);
            },
        );

        return $this->render('guides/what_is_speed_puzzling.html.twig', [
            'distributions' => $distributions,
            'guide_title' => $this->translator->trans('guides.what_is.title', locale: 'en'),
            'guide_description' => $this->translator->trans('guides.what_is.meta_description', locale: 'en'),
            'guide_published' => '2026-07-11',
            'guide_modified' => '2026-07-11',
        ]);
    }
}
