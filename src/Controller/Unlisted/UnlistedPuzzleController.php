<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Unlisted;

use SpeedPuzzling\Web\Controller\DataImport\ImportsHomeController;
use SpeedPuzzling\Web\Services\Unlisted\UnlistedPuzzlePage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * One unlisted puzzle of the signed-in player (docs/features/data-import/unlisted-puzzles.md "The pages"): its
 * results, its owned / wish marks, the file it came from, "It is this catalogue puzzle" and "Remove". Another
 * player's unlisted puzzle - and any for a guest - is a 404.
 */
final class UnlistedPuzzleController extends AbstractController
{
    public function __construct(
        readonly private UnlistedPuzzlePage $page,
    ) {
    }

    #[Route(
        path: '/{_locale}/unlisted-puzzles/{unlistedPuzzleId}',
        name: 'unlisted_puzzle',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'unlistedPuzzleId' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function __invoke(string $unlistedPuzzleId): Response
    {
        $response = $this->render('unlisted_puzzles/detail.html.twig', $this->page->parameters($unlistedPuzzleId));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
