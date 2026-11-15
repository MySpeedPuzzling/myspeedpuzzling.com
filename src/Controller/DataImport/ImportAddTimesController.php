<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Results\ImportAddRow;
use SpeedPuzzling\Web\Services\DataImport\ImportRowTimeCheck;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The times that need a word before adding (docs/features/data-import/adding-screens.md "Unusual times"): the first
 * ready results with a time are shown to the time verification (ImportRowTimeCheck::unusualAmong() - the judgement
 * "Add" itself makes), and the unusual ones are listed with what was expected and "It is right" / "Leave out".
 *
 * A page of its own, loaded into a frame of the review: the check asks about each row on its own, so it is bounded
 * (ROWS_CHECKED) and kept off the review page. A time it did not come to is still asked about when the row is
 * added - the row then waits under "could not be added" with the same two buttons. Another player's import is a 404.
 */
final class ImportAddTimesController extends AbstractController
{
    public const int ROWS_CHECKED = 100;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportAddScreen $getImportAddScreen,
        readonly private DataImportRepository $dataImportRepository,
        readonly private ImportRowTimeCheck $timeCheck,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/add/times',
        name: 'data_import_add_times',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $importId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);
        $rows = $this->getImportAddScreen->timedRows($importId, $player->playerId, self::ROWS_CHECKED);
        $unusual = [];

        $entity = $this->dataImportRepository->getOwnedBy($importId, $player->playerId);

        if ($rows !== []) {
            $assessments = $this->timeCheck->unusualAmong(
                $entity,
                array_map(static fn (ImportAddRow $row): string => $row->rowId, $rows),
            );

            foreach ($rows as $row) {
                $assessment = $assessments[$row->rowId] ?? null;

                if ($assessment !== null) {
                    $unusual[] = ['row' => $row, 'direction' => $assessment->direction()?->value, 'expected' => $assessment->expectedSeconds];
                }
            }
        }

        return $this->render('data_import/add_times.html.twig', [
            'import' => $import,
            'unusual' => $unusual,
            'checked' => count($rows),
            'all_checked' => count($rows) < self::ROWS_CHECKED,
            // One answer for every unusual time of the file - not only the first rows looked at here
            'group_request_ids' => ['right' => Uuid::uuid7()->toString(), 'leave_out' => Uuid::uuid7()->toString()],
            // "These times are right" stands for the whole file: the count is still said before each "Add", with
            // "Change" - the answer removes the round trip, not what the player sees
            'stands' => $entity->hasStandingAnswerFor(ImportRowRefusal::UnusualTime->value),
        ]);
    }
}
