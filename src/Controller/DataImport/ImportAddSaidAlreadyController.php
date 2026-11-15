<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportSaidAlreadyCheck;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Your file says N results are on MySpeedPuzzling already - we did not find them", asked **before** the "Add"
 * (docs/features/data-import/guided-flow.md §5.10, adding-screens.md): the ready results the file marks as already
 * on MySpeedPuzzling are shown to the engine's own question (ImportSaidAlreadyCheck), and the ones it would send
 * back are counted - with the two answers ("They are missing - add them" / "Don't import them") as one group
 * answer given before the "Add". With an answer that stands for the file, the count and the answer are said
 * instead, with "Change".
 *
 * A page of its own, loaded into a frame of the review: the check asks about each row on its own, so it is bounded
 * and kept off the review page. Another player's import is a 404.
 */
final class ImportAddSaidAlreadyController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private DataImportRepository $dataImportRepository,
        readonly private ImportSaidAlreadyCheck $saidAlreadyCheck,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/add/already-on',
        name: 'data_import_add_said',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $importId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $import = $this->getDataImports->byIdOfPlayer($importId, $player->playerId);
        $entity = $this->dataImportRepository->getOwnedBy($importId, $player->playerId);

        return $this->render('data_import/add_said.html.twig', [
            'import' => $import,
            'check' => $this->saidAlreadyCheck->of($entity),
            'stands' => $entity->hasStandingAnswerFor(ImportRowRefusal::SaidAlreadyOnProfile->value),
            'group_request_ids' => ['missing' => Uuid::uuid7()->toString(), 'leave_out' => Uuid::uuid7()->toString()],
            'change_request_id' => Uuid::uuid7()->toString(),
        ]);
    }
}
