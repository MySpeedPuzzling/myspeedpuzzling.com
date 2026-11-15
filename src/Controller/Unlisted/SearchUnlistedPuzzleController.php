<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Unlisted;

use SpeedPuzzling\Web\Controller\DataImport\ImportsHomeController;
use SpeedPuzzling\Web\Services\Unlisted\UnlistedPuzzlePage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * "Search for it" on an unlisted puzzle's page: what the player types - a name, a part of one, a barcode - finds
 * catalogue puzzles, each a button "It is this one" (the import's own search; PairUnlistedPuzzle checks the pick
 * again).
 *
 * A POST although it changes nothing, like the import's chooser: the search is prefilled with the puzzle's name as
 * the file writes it, and nothing of a file may stand in an address. CSRF checked. It answers the page with a 422 -
 * a full-page POST never answers 200, Turbo Drive would drop it.
 */
final class SearchUnlistedPuzzleController extends AbstractController
{
    public function __construct(
        readonly private UnlistedPuzzlePage $page,
    ) {
    }

    #[Route(
        path: '/{_locale}/unlisted-puzzles/{unlistedPuzzleId}/search',
        name: 'unlisted_puzzle_search',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'unlistedPuzzleId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $unlistedPuzzleId): Response
    {
        // Who asks first: a guest must not learn from a CSRF answer that the address means something
        $this->page->ownerPlayerId();

        if ($this->isCsrfTokenValid(UnlistedPuzzlePage::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $typed = trim(mb_substr($request->request->getString('search'), 0, 200));
        $response = $this->render(
            'unlisted_puzzles/detail.html.twig',
            $this->page->parameters($unlistedPuzzleId, $typed),
            new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY),
        );
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
