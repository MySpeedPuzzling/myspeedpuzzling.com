<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Services\SolveTimeDistributionProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "How long does a 1000-piece puzzle take" guide - the answer is computed
 * from real recorded solves, not guessed. English-only by design.
 */
final class GuidePuzzleTimeByPiecesController extends AbstractController
{
    public function __construct(
        readonly private SolveTimeDistributionProvider $solveTimeDistributionProvider,
        readonly private TranslatorInterface $translator,
        readonly private PuzzlingTimeFormatter $timeFormatter,
    ) {
    }

    #[Route(path: '/en/guides/how-long-does-a-1000-piece-puzzle-take', name: 'guide_puzzle_time_by_pieces', defaults: ['_locale' => 'en'])]
    public function __invoke(): Response
    {
        $distributions = $this->solveTimeDistributionProvider->forStandardPiecesBuckets();

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
