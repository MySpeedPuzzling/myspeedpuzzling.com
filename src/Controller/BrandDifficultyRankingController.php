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
 * Public hardest / easiest puzzles of one brand, all piece counts. Everybody
 * sees the ranked names, only members see the difficulty (see PuzzleDifficultyRankings).
 */
final class BrandDifficultyRankingController extends AbstractController
{
    public function __construct(
        readonly private PuzzleDifficultyRankings $puzzleDifficultyRankings,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/znacka/{slug}/nejtezsi',
            'en' => '/en/puzzle/brand/{slug}/hardest',
            'es' => '/es/puzzles/marca/{slug}/mas-dificiles',
            'ja' => '/ja/パズル/ブランド/{slug}/難しい',
            'fr' => '/fr/puzzle/marque/{slug}/plus-difficiles',
            'de' => '/de/puzzle/marke/{slug}/schwierigste',
        ],
        name: 'brand_hardest_puzzles',
        requirements: ['slug' => '[a-z0-9\-]+'],
        defaults: ['direction' => DifficultyRankingDirection::Hardest->value],
        // Same precedence as the hubs: above puzzle_detail's {puzzleId} routes.
        priority: 10,
    )]
    #[Route(
        path: [
            'cs' => '/puzzle/znacka/{slug}/nejlehci',
            'en' => '/en/puzzle/brand/{slug}/easiest',
            'es' => '/es/puzzles/marca/{slug}/mas-faciles',
            'ja' => '/ja/パズル/ブランド/{slug}/簡単',
            'fr' => '/fr/puzzle/marque/{slug}/plus-faciles',
            'de' => '/de/puzzle/marke/{slug}/leichteste',
        ],
        name: 'brand_easiest_puzzles',
        requirements: ['slug' => '[a-z0-9\-]+'],
        defaults: ['direction' => DifficultyRankingDirection::Easiest->value],
        priority: 10,
    )]
    public function __invoke(string $slug, DifficultyRankingDirection $direction): Response
    {
        $availability = $this->puzzleDifficultyRankings->availability();
        $brand = $availability->brand($slug);

        // Unknown brand, or too few rated puzzles for a list
        if ($brand === null) {
            throw $this->createNotFoundException();
        }

        $ranking = $this->puzzleDifficultyRankings->forBrand(
            $brand,
            $direction,
            $this->retrieveLoggedUserProfile->getProfile()?->activeMembership === true,
        );

        return $this->render('puzzle/difficulty_ranking.html.twig', [
            'ranking' => $ranking,
            'pieces' => null,
            'brand' => $brand,
            'availability' => $availability,
        ]);
    }
}
