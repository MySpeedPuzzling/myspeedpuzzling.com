<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class PuzzleDetailController extends AbstractController
{
    public function __construct(
    )
    {
    }

    #[Route(path: '/puzzle/{puzzleId}', name: 'puzzle_detail', methods: ['GET'])]
    public function __invoke(string $puzzleId): Response
    {
        $this->addFlash('primary', 'Puzzle, které jste zkoušeli hledat, u nás nejsou!');
        return $this->redirectToRoute('puzzles');

        return $this->render('puzzle_detail.html.twig', [

        ]);
    }
}
