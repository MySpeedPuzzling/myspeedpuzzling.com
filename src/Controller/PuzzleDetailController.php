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

    #[Route(path: '/puzzle-d/{puzzleId}', name: 'puzzle_detail', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('puzzle_detail.html.twig', [

        ]);
    }
}
