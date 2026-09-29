<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\PuzzleDifficultyRankings;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DifficultyRankingDirection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public hardest / easiest puzzles of one piece count. Everybody sees the
 * ranked names, only members see the difficulty (see PuzzleDifficultyRankings).
 */
final class PiecesDifficultyRankingController extends AbstractController
{
    public function __construct(
        readonly private PuzzleDifficultyRankings $puzzleDifficultyRankings,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/{pieces}-dilku/nejtezsi',
            'en' => '/en/puzzle/{pieces}-pieces/hardest',
            'es' => '/es/puzzles/{pieces}-piezas/mas-dificiles',
            'ja' => '/ja/パズル/{pieces}ピース/難しい',
            'fr' => '/fr/puzzle/{pieces}-pieces/plus-difficiles',
            'de' => '/de/puzzle/{pieces}-teile/schwierigste',
        ],
        name: 'pieces_hardest_puzzles',
        requirements: ['pieces' => '\d{2,5}'],
        defaults: ['direction' => DifficultyRankingDirection::Hardest->value],
        // Same precedence as the hubs: above puzzle_detail's {puzzleId} routes.
        priority: 10,
    )]
    #[Route(
        path: [
            'cs' => '/puzzle/{pieces}-dilku/nejlehci',
            'en' => '/en/puzzle/{pieces}-pieces/easiest',
            'es' => '/es/puzzles/{pieces}-piezas/mas-faciles',
            'ja' => '/ja/パズル/{pieces}ピース/簡単',
            'fr' => '/fr/puzzle/{pieces}-pieces/plus-faciles',
            'de' => '/de/puzzle/{pieces}-teile/leichteste',
        ],
        name: 'pieces_easiest_puzzles',
        requirements: ['pieces' => '\d{2,5}'],
        defaults: ['direction' => DifficultyRankingDirection::Easiest->value],
        priority: 10,
    )]
    public function __invoke(int $pieces, DifficultyRankingDirection $direction): Response
    {
        $ranking = $this->puzzleDifficultyRankings->forPieces(
            $pieces,
            $direction,
            $this->retrieveLoggedUserProfile->getProfile()?->activeMembership === true,
        );

        if ($ranking === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('puzzle/difficulty_ranking.html.twig', [
            'ranking' => $ranking,
            'pieces' => $pieces,
            'brand' => null,
            'availability' => $this->puzzleDifficultyRankings->availability(),
        ]);
    }
}
