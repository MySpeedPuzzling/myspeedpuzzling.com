<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Query\GetImportPeople;
use SpeedPuzzling\Web\Results\ImportAddRow;
use SpeedPuzzling\Web\Results\ImportAddSheetLine;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonText;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Check and add" - the review before adding (docs/features/data-import/guided-flow.md §5.9, adding-screens.md
 * "Review before adding"): what an "Add" would put on the profile as lines with counts, **how the file was read** -
 * per sheet what its rows are added as (solo / pair / team and with whom - a guest or a player -, puzzles owned,
 * wishes), how its dates and times were read and one row as the example -, the first results as they will appear,
 * the rows that are the same result ("one result or three?"), the rows already on the profile, what is not added
 * this time and where it is settled, what an import does not do, and the ONE button. Nothing is added by opening it and nothing starts by itself. The times that may need a word are looked
 * up apart (ImportAddTimesController, a frame of this page).
 *
 * Another player's import is a 404. Five statements, whatever the import's size - six when a sheet names people -:
 * the ready rows are worked out once (the summary), which also names the rows shown first.
 */
final class ImportAddReviewController extends AbstractController
{
    public const int EQUAL_GROUPS_SHOWN = 20;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportAddScreen $getImportAddScreen,
        readonly private GetImportPeople $getImportPeople,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/add',
        name: 'data_import_add',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $importId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);
        $add = $this->getImportAddScreen->summary($importId, $player->playerId, forReview: true);

        if ($add->canAdd === false) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        // The rows the review shows first, and each sheet's example - named by the summary, read in one statement
        $exampleIds = array_values(array_filter(array_map(static fn (ImportAddSheetLine $sheet): null|string => $sheet->exampleId, $add->sheets)));
        $shown = $this->getImportAddScreen->rowsByIds($importId, $player->playerId, array_values(array_unique([...$add->sampleIds, ...$add->knownIds, ...$exampleIds])));

        return $this->render('data_import/add.html.twig', [
            'import' => $import,
            'add' => $add,
            'sheets' => $add->sheets,
            'examples' => $shown,
            // Who the people beside a sheet's line are: a guest or a player - said before the tap
            'partners' => $this->partners($importId, $player->playerId, $add->sheets),
            'sample' => array_values(array_filter(array_map(static fn (string $id): null|ImportAddRow => $shown[$id] ?? null, $add->sampleIds))),
            'equal_groups' => $this->getImportAddScreen->equalGroups($importId, $player->playerId, self::EQUAL_GROUPS_SHOWN),
            'known' => array_values(array_filter(array_map(static fn (string $id): null|ImportAddRow => $shown[$id] ?? null, $add->knownIds))),
            // One id per press of "Add": the same form sent again (a double tap, a resent form) answers the same batch
            'request_id' => Uuid::uuid7()->toString(),
            'daily_limit' => DataImportLimits::MAX_RESULTS_ADDED_PER_DAY,
            // "People in your file" (docs/features/data-import/people.md) - one statement
            'people_counts' => $this->getImportPeople->counts($importId, $player->playerId),
        ]);
    }

    /**
     * The people each sheet's line names, with who each is decided to be. One statement - none when no sheet names
     * anybody. The player themself ("this is you") is on no line.
     *
     * @param list<ImportAddSheetLine> $sheets
     *
     * @return array<string, list<array{name: string, who: string}>> by sheet id
     */
    private function partners(string $importId, string $playerId, array $sheets): array
    {
        $keys = [];

        foreach ($sheets as $sheet) {
            foreach ($sheet->people as $written) {
                $person = ImportPersonText::of($written);

                if ($person !== null) {
                    $keys[$sheet->sheetId][$written] = $person->key;
                }
            }
        }

        if ($keys === []) {
            return [];
        }

        $who = $this->getImportPeople->whoAre($importId, $playerId, array_merge(...array_map(array_values(...), array_values($keys))));
        $partners = [];

        foreach ($keys as $sheetId => $people) {
            foreach ($people as $written => $key) {
                $is = $who[$key] ?? 'guest';

                if ($is !== 'me') {
                    $partners[$sheetId][] = ['name' => (string) $written, 'who' => $is];
                }
            }
        }

        return $partners;
    }
}
