<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

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
 * "How long does a {N}-piece puzzle take" for every standard size except 1000,
 * whose guide came first and keeps its own URL. Every number is live; a size
 * with too few recorded solo solves has no page at all. English-only by design.
 */
final class GuidePuzzleTimeForPiecesController extends AbstractController
{
    private const string PUBLISHED = '2026-09-30';

    public function __construct(
        readonly private SolveTimeDistributionProvider $solveTimeDistributionProvider,
        readonly private PuzzleTimeGuides $puzzleTimeGuides,
        readonly private TranslatorInterface $translator,
        readonly private PuzzlingTimeFormatter $timeFormatter,
    ) {
    }

    #[Route(
        path: '/en/guides/how-long-does-a-{pieces}-piece-puzzle-take',
        name: 'guide_puzzle_time_for_pieces',
        requirements: ['pieces' => PuzzleTimeGuides::GENERIC_GUIDE_PIECES_REQUIREMENT],
        defaults: ['_locale' => 'en'],
    )]
    public function __invoke(int $pieces): Response
    {
        $solo = $this->solveTimeDistributionProvider->snapshot();
        $distribution = $solo->withAtLeast($pieces, PuzzleTimeGuides::MINIMUM_SOLO_SOLVES);

        if ($distribution === null) {
            throw $this->createNotFoundException();
        }

        $duo = $this->solveTimeDistributionProvider->snapshot(PuzzlingType::Duo);
        $team = $this->solveTimeDistributionProvider->snapshot(PuzzlingType::Team);

        [$smaller, $larger] = $solo->neighboursOf($pieces, PuzzleTimeGuides::MINIMUM_SOLO_SOLVES);

        return $this->render(sprintf('guides/how_long_by_pieces/%d.html.twig', $pieces), [
            'pieces' => $pieces,
            'distribution' => $distribution,
            'duo' => $duo->withAtLeast($pieces, PuzzleTimeGuides::MINIMUM_GROUP_SOLVES),
            'team' => $team->withAtLeast($pieces, PuzzleTimeGuides::MINIMUM_GROUP_SOLVES),
            'smaller' => $smaller,
            'larger' => $larger,
            'all_sizes_solves' => $solo->totalSolves(),
            'guide_paths' => $this->puzzleTimeGuides->paths(),
            'guide_title' => $this->translator->trans('guides.how_long_pieces.title', ['%pieces%' => $pieces], locale: 'en'),
            'guide_description' => $this->translator->trans('guides.how_long_pieces.meta_description', [
                '%pieces%' => $pieces,
                '%count%' => number_format($distribution->solvesCount),
                '%median%' => $this->timeFormatter->compactTime($distribution->medianSeconds),
            ], locale: 'en'),
            'guide_published' => self::PUBLISHED,
            'guide_modified' => SolveTimeDistributionSnapshot::latestComputedAt($solo, $duo, $team)->format('Y-m-d'),
        ]);
    }
}
