<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Results\ImportAddRow;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The review before adding (docs/features/data-import/adding-screens.md "Review before adding"): what an "Add" would
 * put on the profile - counts by kind, a line per sheet, the first results as they will appear - the rows that are
 * the same result ("one result or three?"), the rows already on the profile, what an import does not do, and the
 * button. Nothing is added by opening it and nothing starts by itself. The times that may need a word are looked
 * up apart (ImportAddTimesController, a frame of this page).
 *
 * Another player's import is a 404. Four statements, whatever the import's size - the ready rows are worked out
 * once (the summary), which also names the rows shown first.
 */
final class ImportAddReviewController extends AbstractController
{
    public const int EQUAL_GROUPS_SHOWN = 20;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportAddScreen $getImportAddScreen,
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

        // The rows the review shows first - named by the summary, read in one statement
        $shown = $this->getImportAddScreen->rowsByIds($importId, $player->playerId, [...$add->sampleIds, ...$add->knownIds]);

        return $this->render('data_import/add.html.twig', [
            'import' => $import,
            'add' => $add,
            'sheets' => $add->sheets,
            'sample' => array_values(array_filter(array_map(static fn (string $id): null|ImportAddRow => $shown[$id] ?? null, $add->sampleIds))),
            'equal_groups' => $this->getImportAddScreen->equalGroups($importId, $player->playerId, self::EQUAL_GROUPS_SHOWN),
            'known' => array_values(array_filter(array_map(static fn (string $id): null|ImportAddRow => $shown[$id] ?? null, $add->knownIds))),
            // One id per press of "Add": the same form sent again (a double tap, a resent form) answers the same batch
            'request_id' => Uuid::uuid7()->toString(),
            'daily_limit' => DataImportLimits::MAX_RESULTS_ADDED_PER_DAY,
        ]);
    }
}
