<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPuzzlesOverview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class PuzzlesController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzlesOverview $getPuzzlesOverview,
    )
    {
    }

    #[Route(path: '/puzzle', name: 'puzzles', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('puzzles.html.twig', [
            'puzzles' => $this->getPuzzlesOverview->all(),
        ]);
    }
}
