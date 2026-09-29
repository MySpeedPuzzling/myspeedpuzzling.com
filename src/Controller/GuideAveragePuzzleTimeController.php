<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Results\SolveTimeDistribution;
use SpeedPuzzling\Web\Results\SolveTimeDistributionSnapshot;
use SpeedPuzzling\Web\Services\PuzzleTimeGuides;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Services\SolveTimeDistributionProvider;
use SpeedPuzzling\Web\Value\PuzzlingType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Average puzzle time by piece count" - the pillar of the "how long" guides: one
 * table of every standard size, solo, pair and team, linking to each size's guide.
 * English-only by design.
 */
final class GuideAveragePuzzleTimeController extends AbstractController
{
    private const string PUBLISHED = '2026-09-30';

    public function __construct(
        readonly private SolveTimeDistributionProvider $solveTimeDistributionProvider,
        readonly private PuzzleTimeGuides $puzzleTimeGuides,
        readonly private TranslatorInterface $translator,
        readonly private PuzzlingTimeFormatter $timeFormatter,
    ) {
    }

    #[Route(path: '/en/guides/average-puzzle-time-by-piece-count', name: 'guide_average_puzzle_time', defaults: ['_locale' => 'en'])]
    public function __invoke(): Response
    {
        $solo = $this->solveTimeDistributionProvider->snapshot();
        $duo = $this->solveTimeDistributionProvider->snapshot(PuzzlingType::Duo);
        $team = $this->solveTimeDistributionProvider->snapshot(PuzzlingType::Team);

        /** @var list<array{pieces: int, solo: SolveTimeDistribution, duo: null|SolveTimeDistribution, team: null|SolveTimeDistribution}> $rows */
        $rows = [];

        foreach (SolveTimeDistributionProvider::PIECES_BUCKETS as $pieces) {
            $soloDistribution = $solo->withAtLeast($pieces, PuzzleTimeGuides::MINIMUM_SOLO_SOLVES);

            if ($soloDistribution === null) {
                continue;
            }

            $rows[] = [
                'pieces' => $pieces,
                'solo' => $soloDistribution,
                'duo' => $duo->withAtLeast($pieces, PuzzleTimeGuides::MINIMUM_GROUP_SOLVES),
                'team' => $team->withAtLeast($pieces, PuzzleTimeGuides::MINIMUM_GROUP_SOLVES),
            ];
        }

        $solo500 = $solo->withAtLeast(500, PuzzleTimeGuides::MINIMUM_SOLO_SOLVES);
        $solo1000 = $solo->withAtLeast(1000, PuzzleTimeGuides::MINIMUM_SOLO_SOLVES);

        $description = $solo500 !== null && $solo1000 !== null
            ? $this->translator->trans('guides.average.meta_description', [
                '%count%' => number_format($solo->totalSolves()),
                '%median_500%' => $this->timeFormatter->compactTime($solo500->medianSeconds),
                '%median_1000%' => $this->timeFormatter->compactTime($solo1000->medianSeconds),
            ], locale: 'en')
            : $this->translator->trans('guides.average.meta_description_fallback', locale: 'en');

        return $this->render('guides/average_puzzle_time.html.twig', [
            'rows' => $rows,
            'solo500' => $solo500,
            'solo1000' => $solo1000,
            'total_solo_solves' => $solo->totalSolves(),
            'guide_paths' => $this->puzzleTimeGuides->paths(),
            'guide_title' => $this->translator->trans('guides.average.title', locale: 'en'),
            'guide_description' => $description,
            'guide_published' => self::PUBLISHED,
            'guide_modified' => SolveTimeDistributionSnapshot::latestComputedAt($solo, $duo, $team)->format('Y-m-d'),
        ]);
    }
}
