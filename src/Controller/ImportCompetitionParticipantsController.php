<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\FormData\ExcelImportFormData;
use SpeedPuzzling\Web\FormType\ExcelImportFormType;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\CompetitionParticipantImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ImportCompetitionParticipantsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionParticipantImporter $importer,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/import-ucastniku-udalosti/{competitionId}',
            'en' => '/en/import-event-participants/{competitionId}',
        ],
        name: 'import_competition_participants',
        methods: ['POST'],
    )]
    public function __invoke(string $competitionId, Request $request): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $formData = new ExcelImportFormData();
        $form = $this->createForm(ExcelImportFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $formData->file !== null) {
            $result = $this->importer->import($competitionId, $formData->file->getPathname());

            $summary = sprintf(
                'Import complete: %d added, %d updated, %d soft-deleted.',
                $result->added,
                $result->updated,
                $result->softDeleted,
            );

            $this->addFlash('success', $summary);

            foreach ($result->warnings as $warning) {
                $this->addFlash('warning', $warning);
            }

            foreach ($result->errors as $error) {
                $this->addFlash('danger', $error);
            }
        } else {
            $this->addFlash('danger', 'Invalid file upload.');
        }

        return $this->redirectToRoute('manage_competition_participants', [
            'competitionId' => $competitionId,
        ]);
    }
}
