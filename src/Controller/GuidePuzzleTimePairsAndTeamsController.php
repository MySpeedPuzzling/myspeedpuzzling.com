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
 * "How long does a 1000-piece puzzle take with 2 people" - solo vs. pair vs. team
 * for the two sizes groups actually race (500 and 1000), plus the exact group
 * sizes behind "team" (3 or more people). English-only by design.
 */
final class GuidePuzzleTimePairsAndTeamsController extends AbstractController
{
    private const string PUBLISHED = '2026-09-30';

    /**
     * @var list<int>
     */
    private const array PIECES = [500, 1000];

    public function __construct(
        readonly private SolveTimeDistributionProvider $solveTimeDistributionProvider,
        readonly private PuzzleTimeGuides $puzzleTimeGuides,
        readonly private TranslatorInterface $translator,
        readonly private PuzzlingTimeFormatter $timeFormatter,
    ) {
    }

    #[Route(path: '/en/guides/how-long-does-a-1000-piece-puzzle-take-with-2-people', name: 'guide_puzzle_time_pairs_and_teams', defaults: ['_locale' => 'en'])]
    public function __invoke(): Response
    {
        $solo = $this->solveTimeDistributionProvider->snapshot();
        $duo = $this->solveTimeDistributionProvider->snapshot(PuzzlingType::Duo);
        $team = $this->solveTimeDistributionProvider->snapshot(PuzzlingType::Team);
        $groupsOfThree = $this->solveTimeDistributionProvider->snapshotForGroupSize(3);
        $groupsOfFour = $this->solveTimeDistributionProvider->snapshotForGroupSize(4);

        /** @var array<int, array{solo: null|SolveTimeDistribution, duo: null|SolveTimeDistribution, team: null|SolveTimeDistribution, three: null|SolveTimeDistribution, four: null|SolveTimeDistribution}> $sizes */
        $sizes = [];

        foreach (self::PIECES as $pieces) {
            $sizes[$pieces] = [
                'solo' => $solo->withAtLeast($pieces, PuzzleTimeGuides::MINIMUM_SOLO_SOLVES),
                'duo' => $duo->withAtLeast($pieces, PuzzleTimeGuides::MINIMUM_GROUP_SOLVES),
                'team' => $team->withAtLeast($pieces, PuzzleTimeGuides::MINIMUM_GROUP_SOLVES),
                'three' => $groupsOfThree->withAtLeast($pieces, PuzzleTimeGuides::MINIMUM_GROUP_SOLVES),
                'four' => $groupsOfFour->withAtLeast($pieces, PuzzleTimeGuides::MINIMUM_GROUP_SOLVES),
            ];
        }

        $solo1000 = $sizes[1000]['solo'];
        $duo1000 = $sizes[1000]['duo'];

        $description = $solo1000 !== null && $duo1000 !== null
            ? $this->translator->trans('guides.how_long_pairs.meta_description', [
                '%count%' => number_format($duo1000->solvesCount),
                '%median%' => $this->timeFormatter->compactTime($duo1000->medianSeconds),
                '%solo_median%' => $this->timeFormatter->compactTime($solo1000->medianSeconds),
            ], locale: 'en')
            : $this->translator->trans('guides.how_long_pairs.meta_description_fallback', locale: 'en');

        return $this->render('guides/how_long_pairs_and_teams.html.twig', [
            'sizes' => $sizes,
            'guide_paths' => $this->puzzleTimeGuides->paths(),
            'guide_title' => $this->translator->trans('guides.how_long_pairs.title', locale: 'en'),
            'guide_description' => $description,
            'guide_published' => self::PUBLISHED,
            'guide_modified' => SolveTimeDistributionSnapshot::latestComputedAt($solo, $duo, $team, $groupsOfThree, $groupsOfFour)->format('Y-m-d'),
        ]);
    }
}
