<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
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
 * Another player's import is a 404. Six statements, whatever the import's size.
 */
final class ImportAddReviewController extends AbstractController
{
    public const int SAMPLE = 5;
    public const int EQUAL_GROUPS_SHOWN = 20;
    public const int KNOWN_SHOWN = 20;

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
        $add = $this->getImportAddScreen->summary($importId, $player->playerId);

        if ($add->canAdd === false) {
            return $this->redirectToRoute('data_import_detail', ['importId' => $importId]);
        }

        return $this->render('data_import/add.html.twig', [
            'import' => $import,
            'add' => $add,
            'sheets' => $this->getImportAddScreen->readyBySheet($importId, $player->playerId),
            'sample' => $this->getImportAddScreen->readyRows($importId, $player->playerId, 'results', self::SAMPLE),
            'equal_groups' => $this->getImportAddScreen->equalGroups($importId, $player->playerId, self::EQUAL_GROUPS_SHOWN),
            'known' => $this->getImportAddScreen->readyRows($importId, $player->playerId, 'known', self::KNOWN_SHOWN),
            // One id per press of "Add": the same form sent again (a double tap, a resent form) answers the same batch
            'request_id' => Uuid::uuid7()->toString(),
            'daily_limit' => DataImportLimits::MAX_RESULTS_ADDED_PER_DAY,
        ]);
    }
}
