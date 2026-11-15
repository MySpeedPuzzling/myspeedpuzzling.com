<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin\DataImport;

use SpeedPuzzling\Web\Query\GetImportBatches;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admins only (docs/features/data-import/adding.md "The audit page"): the safety switch, and what the spreadsheet
 * imports add - the latest imports, "Add"s and Undos across players. English only, like the other admin pages.
 */
#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class ImportsController extends AbstractController
{
    public function __construct(
        private readonly GetImportBatches $getImportBatches,
    ) {
    }

    #[Route(path: '/admin/imports', name: 'admin_imports', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('admin/imports.html.twig', [
            'switch' => $this->getImportBatches->addingSwitch(),
            'imports' => $this->getImportBatches->latestImports(),
            'batches' => $this->getImportBatches->latestBatches(),
            'undos' => $this->getImportBatches->latestUndos(),
        ]);
    }
}
