<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetImportBatches;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One "Add" for admins: its numbers, its rows by outcome (what each made, by id - never a cell), the Undos of it,
 * and the admin's Undo of the whole batch.
 */
#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class ImportBatchController extends AbstractController
{
    public function __construct(
        private readonly GetImportBatches $getImportBatches,
    ) {
    }

    #[Route(
        path: '/admin/imports/batches/{batchId}',
        name: 'admin_import_batch',
        requirements: ['batchId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $batchId): Response
    {
        $batch = $this->getImportBatches->batch($batchId);

        if ($batch === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/import_batch.html.twig', [
            'batch' => $batch,
            'rows' => $this->getImportBatches->rowsOfBatch($batchId),
            'undos' => $this->getImportBatches->latestUndos(batchId: $batchId),
            // One press of "Undo" = one request: sent again it answers the same Undo
            'undo_request_id' => Uuid::uuid7()->toString(),
        ]);
    }
}
