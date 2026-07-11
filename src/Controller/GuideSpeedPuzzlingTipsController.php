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
 * "Speed puzzling tips" guide. English-only by design.
 */
final class GuideSpeedPuzzlingTipsController extends AbstractController
{
    public function __construct(
        readonly private GetSolveTimeDistribution $getSolveTimeDistribution,
        readonly private CacheInterface $cache,
        readonly private TranslatorInterface $translator,
        #[Autowire(param: 'kernel.environment')]
        readonly private string $environment,
    ) {
    }

    #[Route(path: '/en/guides/speed-puzzling-tips', name: 'guide_speed_puzzling_tips', defaults: ['_locale' => 'en'])]
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

        return $this->render('guides/speed_puzzling_tips.html.twig', [
            'distributions' => $distributions,
            'guide_title' => $this->translator->trans('guides.tips.title', locale: 'en'),
            'guide_description' => $this->translator->trans('guides.tips.meta_description', locale: 'en'),
            'guide_published' => '2026-07-11',
            'guide_modified' => '2026-07-11',
        ]);
    }
}
