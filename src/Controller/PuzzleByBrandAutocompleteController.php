<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\PuzzleChoicesBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PuzzleByBrandAutocompleteController extends AbstractController
{
    public function __construct(
        readonly private SearchPuzzle $searchPuzzle,
        readonly private PuzzleChoicesBuilder $puzzleChoicesBuilder,
    ) {
    }

    #[Route(
        path: '/{_locale}/puzzle-by-brand-autocomplete/',
        name: 'puzzle_by_brand_autocomplete',
    )]
    public function __invoke(Request $request): Response
    {
        /** @var string|null $brandSearch */
        $brandSearch = $request->query->get('brand');

        if (!is_string($brandSearch) || Uuid::isValid((string) $brandSearch) === false) {
            return $this->json(['error' => 'Unknown brand id'], 404);
        }

        // The add-to-round form: whoever organises the competition also gets its own secret puzzles - one puzzle may
        // serve a round of every category. Anybody else never gets a puzzle before its hide_until.
        $competitionId = $request->query->getString('competition');
        $secretPuzzlesOfCompetition = Uuid::isValid($competitionId) && $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId)
            ? $competitionId
            : null;

        return new JsonResponse([
            'results' => $this->puzzleChoicesBuilder->build(
                $this->searchPuzzle->byBrandId($brandSearch, $secretPuzzlesOfCompetition),
                $request->getLocale(),
            ),
        ]);
    }
}
