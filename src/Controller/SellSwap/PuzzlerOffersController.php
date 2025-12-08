<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\SellSwap;

use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PuzzlerOffersController extends AbstractController
{
    public function __construct(
        readonly private GetSellSwapListItems $getSellSwapListItems,
        readonly private GetPuzzleOverview $getPuzzleOverview,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/{puzzleId}/nabidky',
            'en' => '/en/puzzle/{puzzleId}/offers',
            'es' => '/es/puzzle/{puzzleId}/ofertas',
            'ja' => '/ja/パズル/{puzzleId}/offers',
            'fr' => '/fr/puzzle/{puzzleId}/offres',
            'de' => '/de/puzzle/{puzzleId}/angebote',
        ],
        name: 'puzzler_offers',
        methods: ['GET'],
    )]
    public function __invoke(string $puzzleId): Response
    {
        $puzzle = $this->getPuzzleOverview->byId($puzzleId);
        $offers = $this->getSellSwapListItems->byPuzzleId($puzzleId);

        return $this->render('sell-swap/offers.html.twig', [
            'puzzle' => $puzzle,
            'offers' => $offers,
        ]);
    }
}
