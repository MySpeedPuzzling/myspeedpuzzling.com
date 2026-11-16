<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Query\GetImportSheetScreen;
use SpeedPuzzling\Web\Results\ImportAddListRow;
use SpeedPuzzling\Web\Results\ImportAddRow;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
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
    /** What a sheet that was left out on this screen can be put back as: the kinds whose rows the list shows */
    public const array PUT_BACK_AS = [DataImportSheetKind::Results, DataImportSheetKind::Owned, DataImportSheetKind::WishList];

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportAddScreen $getImportAddScreen,
        readonly private GetImportSheetScreen $getImportSheetScreen,
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

        // A sheet that was just set to "not imported" (ChangeImportAddListController): named by the address, so
        // the list says so and offers "Put back" on every render - also when the page asks again by itself while
        // the file is read again. Only while the sheet IS left out, and only as a kind a sheet of puzzles has.
        $leftOutSheet = null;
        $left = strtolower($request->query->getString('left'));
        $was = DataImportSheetKind::tryFrom($request->query->getString('was'));

        if ($left === '' && $request->hasPreviousSession()) {
            // Opened without it (a reload of an address that did not hold it, the list opened again): the sheet this
            // visit left out here, and what it was - the offer must not hang on the address alone
            $remembered = $request->getSession()->get(ChangeImportAddListController::SESSION_LEFT . $importId);
            $left = is_string($remembered) ? $remembered : '';
            $rememberedKind = $left === '' ? null : $request->getSession()->get(ChangeImportAddListController::SESSION_WAS . $left);
            $was = is_string($rememberedKind) ? DataImportSheetKind::tryFrom($rememberedKind) : null;
        }

        if (Uuid::isValid($left)) {
            // Another import's sheet, or none: a 404
            $sheet = $this->getImportSheetScreen->sheet($importId, $left, $player->playerId);

            if ($sheet->kind === DataImportSheetKind::NotPuzzles && $sheet->beingSetUp) {
                // "Put back" only as a kind the sheet can be put back as - without one the list says what was done, and
                // the way back is step 2
                $leftOutSheet = ['sheet' => $left, 'kind' => in_array($was, self::PUT_BACK_AS, true) ? $was->value : null, 'title' => trim($sheet->title)];
            }
        }

        if ($add->canAdd === false && $leftOutSheet === null) {
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

        // Rows that are on the profile already are not "what will be added": listed apart, folded, without tick boxes -
        // the first of them, named by the summary (no further run of the ready rows' statement)
        // ... and so are the rows that repeat another row (counted once). One light statement for both
        $folded = [...$add->knownIds, ...$add->repeatIds];
        $known = $folded === [] ? [] : $this->getImportAddScreen->rowsByIds($importId, $player->playerId, $folded);
        $onProfile = array_values(array_filter(array_map(static fn (string $id): null|ImportAddRow => $known[$id] ?? null, $add->knownIds)));
        $repeats = array_values(array_filter(array_map(static fn (string $id): null|ImportAddRow => $known[$id] ?? null, $add->repeatIds)));

        return $this->render('data_import/add_list.html.twig', [
            'left_out_sheet' => $leftOutSheet,
            // The list's own address as it stands (the filters, the page, the sheet that is left out): the script puts
            // it in the address bar after a swap of the frame, so a reload shows this list
            'list_address' => $this->generateUrl('data_import_add_list', array_filter([
                'importId' => $importId,
                ...$filters,
                'page' => $page > 1 ? $page : null,
                'left' => $leftOutSheet['sheet'] ?? null,
                'was' => $leftOutSheet['kind'] ?? null,
            ], static fn (mixed $value): bool => $value !== null)),
            'coming_back' => $comingBack,
            'back' => $comingBack !== null ? $back : null,
            'import' => $import,
            'add' => $add,
            'on_profile' => $onProfile,
            'repeats' => $repeats,
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
