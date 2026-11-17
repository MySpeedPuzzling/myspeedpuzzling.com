<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Services\PuzzleChoicesBuilder;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleSearchQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The puzzle search across every brand for the pickers that start from the puzzle (the creation wizard's puzzle
 * pickers, puzzle_search_picker_controller.js): a typed name, barcode or brand code → the best matches, each with its
 * brand. The site's own search (SearchPuzzle::byUserInput, "best match" - PuzzleTextSearch on the search keys and their
 * trigram indexes), so it finds what the puzzles page finds: never a secret puzzle (hide_until), a puzzle whose picture
 * is still secret only by its name, without picture and codes. Public like the catalogue and the brand picker
 * (PuzzleByBrandAutocompleteController) - an organiser's own secret puzzles stay on the brand path.
 */
final class PuzzleSearchAutocompleteController extends AbstractController
{
    public const int LIMIT = 15;

    // One letter matches half the catalogue - nothing is asked before the second
    public const int MIN_LENGTH = 2;

    public function __construct(
        readonly private SearchPuzzle $searchPuzzle,
        readonly private PuzzleChoicesBuilder $puzzleChoicesBuilder,
    ) {
    }

    #[Route(
        path: '/{_locale}/puzzle-search-autocomplete/',
        name: 'puzzle_search_autocomplete',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de'],
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $search = trim($request->query->getString('query'));

        if (mb_strlen(PuzzleSearchQuery::fromUserInput($search)->folded) < self::MIN_LENGTH) {
            return new JsonResponse(['results' => []]);
        }

        $puzzles = $this->searchPuzzle->byUserInput(
            brandId: null,
            search: $search,
            pieces: PiecesRange::any(),
            tag: null,
            sortBy: 'best-match',
            limit: self::LIMIT,
        );

        return new JsonResponse([
            'results' => $this->puzzleChoicesBuilder->buildWithBrands($puzzles, $request->getLocale()),
        ]);
    }
}
