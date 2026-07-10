<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Common plural slip: /en/puzzles - permanently redirect to /en/puzzle.
 */
final class PuzzlesPluralRedirectController extends AbstractController
{
    #[Route(
        path: '/en/puzzles',
        name: 'puzzles_plural_redirect',
    )]
    public function __invoke(): Response
    {
        return $this->redirectToRoute('puzzles', [
            '_locale' => 'en',
        ], Response::HTTP_MOVED_PERMANENTLY);
    }
}
