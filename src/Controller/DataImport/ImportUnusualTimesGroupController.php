<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Services\DataImport\ImportUnusualTimesSummary;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The group of rows that wait with an unusual time, before one answer for all of them
 * (docs/features/data-import/group-answers.md "Unusual times"): how many are faster / slower than expected, how
 * far off, five examples with what was expected, "most of the times in this file…" when that is so - and then the
 * two buttons. A page of its own, loaded into a frame of the done page and of the list (the summary asks the time
 * verification about up to 200 rows - never on those pages themselves). Another player's import is a 404.
 */
final class ImportUnusualTimesGroupController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportAddScreen $getImportAddScreen,
        readonly private ImportUnusualTimesSummary $summary,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/not-added/times',
        name: 'data_import_not_added_times',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);
        $batchId = Uuid::isValid($request->query->getString('batch')) ? strtolower($request->query->getString('batch')) : null;
        $counts = $this->getImportAddScreen->notAddedByReason($importId, $player->playerId, $batchId);

        return $this->render('data_import/not_added_times.html.twig', [
            'import' => $import,
            'batch_id' => $batchId,
            'summary' => $this->summary->of($importId, $player->playerId, $batchId, $counts),
            'request_ids' => ['right' => Uuid::uuid7()->toString(), 'leave_out' => Uuid::uuid7()->toString()],
        ]);
    }
}
