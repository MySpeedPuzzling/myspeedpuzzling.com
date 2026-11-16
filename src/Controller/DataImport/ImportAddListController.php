<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Results\ImportAddListRow;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "What will be added" (docs/features/data-import/guided-flow.md §7): every row an "Add" would take right now -
 * the engine's own ready rows (GetImportAddScreen::readyRows(), on DataImportRowRepository::sqlReadyRows()) - fifty
 * a page, in the file's order under their sheet's name, each with "Don't import"; and, in their place, the rows the
 * player said that about, each with "Put back". By what a row makes (`makes`), by sheet (`sheet`) and by a part of
 * the puzzle's name (`q` - the player's own text, the only thing of a file an address here ever holds). Nothing is
 * added from this page: the button is on "Check and add" only.
 *
 * Another player's import is a 404. Three statements, whatever the import's size.
 */
final class ImportAddListController extends AbstractController
{
    public const array MAKES = ['results', 'collection', 'wish'];

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportAddScreen $getImportAddScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/add/list',
        name: 'data_import_add_list',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);
        // What an "Add" would do now, and the sheets that have something ready - one statement
        $add = $this->getImportAddScreen->summary($importId, $player->playerId, forReview: true);

        if ($add->canAdd === false) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        $filters = self::filters($request->query->all());
        $page = max(1, $request->query->getInt('page', 1));
        $list = $this->getImportAddScreen->readyRows(
            $importId,
            $player->playerId,
            $filters['makes'] ?? null,
            $filters['sheet'] ?? null,
            $filters['q'] ?? '',
            GetImportAddScreen::ROWS_PER_PAGE,
            ($page - 1) * GetImportAddScreen::ROWS_PER_PAGE,
        );

        if ($list['rows'] === [] && $page > 1 && $request->query->has('back') === false) {
            // A page that is not there any more (rows were added, a filter narrowed): the first one
            return $this->redirectToRoute('data_import_add_list', ['importId' => $importId, ...$filters]);
        }

        // "Don't import any of this puzzle": only where a search narrowed the list to the rows of one puzzle
        $onePuzzle = null;
        $taken = array_values(array_filter($list['rows'], static fn (ImportAddListRow $row): bool => $row->notImported === false));
        $puzzleIds = array_values(array_unique(array_map(static fn (ImportAddListRow $row): string => (string) $row->row->importPuzzleId, $taken)));

        if (isset($filters['q']) && count($taken) > 1 && count($taken) === $list['taken'] && count($puzzleIds) === 1 && $puzzleIds[0] !== '') {
            $onePuzzle = ['id' => $puzzleIds[0], 'rows' => count($taken), 'name' => $taken[0]->row->puzzleName ?? $taken[0]->row->said->text('name')];
        }

        // A row that was just put back and is not ready again yet (its puzzle is matched again in a moment): shown
        // in its place as "coming back", and the page asks again until it is (add_list.html.twig)
        $comingBack = null;
        $back = strtolower($request->query->getString('back'));

        if (Uuid::isValid($back) && array_filter($list['rows'], static fn (ImportAddListRow $row): bool => $row->row->rowId === $back) === []) {
            $comingBack = $this->getImportAddScreen->rowsByIds($importId, $player->playerId, [$back])[$back] ?? null;
            // Only a row that waits to be ready: not one that is left out (again), added, or of no use
            $comingBack = $comingBack !== null && $comingBack->state === 'waiting' && $comingBack->solvingTimeId === null ? $comingBack : null;
        }

        return $this->render('data_import/add_list.html.twig', [
            'coming_back' => $comingBack,
            'back' => $comingBack !== null ? $back : null,
            'import' => $import,
            'add' => $add,
            'sheets' => $add->sheets,
            'rows' => $list['rows'],
            'total' => $list['total'],
            'filters' => $filters,
            'one_puzzle' => $onePuzzle,
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / GetImportAddScreen::ROWS_PER_PAGE)),
        ]);
    }

    /**
     * What the address may say about the list - and nothing else: what a row makes, a sheet's id, the player's own
     * search text. Shared with the POST that answers back to the same list.
     *
     * @param array<array-key, mixed> $given
     *
     * @return array{makes?: string, sheet?: string, q?: string}
     */
    public static function filters(array $given): array
    {
        $filters = [];
        $makes = $given['makes'] ?? null;
        $sheet = $given['sheet'] ?? null;
        $search = $given['q'] ?? null;

        if (is_string($makes) && in_array($makes, self::MAKES, true)) {
            $filters['makes'] = $makes;
        }

        if (is_string($sheet) && Uuid::isValid($sheet)) {
            $filters['sheet'] = strtolower($sheet);
        }

        if (is_string($search) && trim($search) !== '') {
            $filters['q'] = trim(mb_substr($search, 0, 100));
        }

        return $filters;
    }
}
