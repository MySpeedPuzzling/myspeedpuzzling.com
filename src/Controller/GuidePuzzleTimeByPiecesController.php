<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetSolveTimeDistribution;
use SpeedPuzzling\Web\Results\SolveTimeDistribution;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "How long does a 1000-piece puzzle take" guide - the answer is computed
 * from real recorded solves, not guessed. English-only by design.
 */
final class GuidePuzzleTimeByPiecesController extends AbstractController
{
    /**
     * Standard retail piece counts; every bucket had well over 100 recorded
     * solo solves in production when the guide launched.
     *
     * @var list<int>
     */
    public const array PIECES_BUCKETS = [100, 200, 300, 500, 1000, 1500, 2000];

    public const string STATS_CACHE_KEY = 'guides_solve_time_distribution_v1';

    public const int STATS_CACHE_TTL = 21600; // 6 hours

    public function __construct(
        readonly private GetSolveTimeDistribution $getSolveTimeDistribution,
        readonly private CacheInterface $cache,
        readonly private TranslatorInterface $translator,
        readonly private PuzzlingTimeFormatter $timeFormatter,
        #[Autowire(param: 'kernel.environment')]
        readonly private string $environment,
    ) {
    }

    #[Route(path: '/en/guides/how-long-does-a-1000-piece-puzzle-take', name: 'guide_puzzle_time_by_pieces', defaults: ['_locale' => 'en'])]
    public function __invoke(): Response
    {
        /** @var array<int, SolveTimeDistribution> $distributions */
        $distributions = $this->cache->get(
            sprintf('%s_%s', self::STATS_CACHE_KEY, $this->environment),
            function (ItemInterface $item): array {
                $item->expiresAfter(self::STATS_CACHE_TTL);

                return $this->getSolveTimeDistribution->byPiecesCounts(self::PIECES_BUCKETS);
            },
        );

        $distribution1000 = $distributions[1000] ?? null;

        $description = $distribution1000 !== null
            ? $this->translator->trans('guides.how_long.meta_description', [
                '%count%' => number_format($distribution1000->solvesCount),
                '%median%' => $this->timeFormatter->compactTime($distribution1000->medianSeconds),
            ], locale: 'en')
            : $this->translator->trans('guides.how_long.meta_description_fallback', locale: 'en');

        return $this->render('guides/how_long_1000_pieces.html.twig', [
            'distributions' => $distributions,
            'guide_title' => $this->translator->trans('guides.how_long.title', locale: 'en'),
            'guide_description' => $description,
            'guide_published' => '2026-07-11',
            'guide_modified' => '2026-07-11',
        ]);
    }
}
