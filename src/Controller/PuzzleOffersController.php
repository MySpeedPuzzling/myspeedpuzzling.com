<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleOffers;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PuzzleOffersController extends AbstractController
{
    public function __construct(
        readonly private PuzzleRepository $puzzleRepository,
        readonly private GetPuzzleOffers $getPuzzleOffers,
    ) {
    }

    #[Route(
        path: '/en/puzzle/{puzzleId}/offers',
        name: 'puzzle_offers',
        methods: ['GET'],
    )]
    public function __invoke(string $puzzleId): Response
    {
        try {
            $puzzle = $this->puzzleRepository->get($puzzleId);
        } catch (PuzzleNotFound) {
            throw $this->createNotFoundException();
        }

        $offers = $this->getPuzzleOffers->byPuzzle($puzzleId);

        return $this->render('puzzle_offers.html.twig', [
            'puzzle' => $puzzle,
            'offers' => $offers,
        ]);
    }
}
