<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\UndoImportRows;
use SpeedPuzzling\Web\Query\GetImportBatches;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\AddImportRowsOutcome;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The emergency lever (docs/features/data-import/adding.md "The emergency runbook"): an admin takes back everything
 * one "Add" of any player added - the players' own Undo message, credited to the admin, with a reason. A result its
 * player changed since is kept.
 */
#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class UndoImportBatchController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'admin-import-batch-undo';

    public function __construct(
        private readonly GetImportBatches $getImportBatches,
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: '/admin/imports/batches/{batchId}/undo',
        name: 'admin_import_batch_undo',
        requirements: ['batchId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $batchId): Response
    {
        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $batch = $this->getImportBatches->batch($batchId);
        $admin = $this->retrieveLoggedUserProfile->getProfile();
        assert($admin !== null);

        if ($batch === null || is_string($batch['import_id'] ?? null) === false) {
            throw $this->createNotFoundException();
        }

        $reason = trim($request->request->getString('reason'));
        $requestId = $request->request->getString('requestId');

        if ($reason === '' || Uuid::isValid($requestId) === false) {
            $this->addFlash('danger', 'An Undo by an admin needs a reason.');

            return $this->redirectToRoute('admin_import_batch', ['batchId' => $batchId]);
        }

        $outcome = $this->messageBus->dispatch(new UndoImportRows(
            requestId: $requestId,
            importId: $batch['import_id'],
            requestedByPlayerId: $admin->playerId,
            scope: DataImportUndoScope::Batch,
            batchId: $batchId,
            asAdmin: true,
            reason: $reason,
        ))->last(HandledStamp::class)?->getResult();

        if ($outcome instanceof AddImportRowsOutcome && $outcome->isStarted()) {
            $this->addFlash('warning', sprintf('The Undo of %d rows is on its way - reload to follow it.', $outcome->rows));
        } else {
            $this->addFlash('info', 'Nothing of this batch is left to take back.');
        }

        return $this->redirectToRoute('admin_import_batch', ['batchId' => $batchId]);
    }
}
