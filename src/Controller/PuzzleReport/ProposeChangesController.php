<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PuzzleReport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\ProposePuzzleChangesFormData;
use SpeedPuzzling\Web\FormData\ReportDuplicatePuzzleFormData;
use SpeedPuzzling\Web\FormType\ProposePuzzleChangesFormType;
use SpeedPuzzling\Web\FormType\ReportDuplicatePuzzleFormType;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Query\GetPendingPuzzleProposals;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class ProposeChangesController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleOverview $getPuzzleOverview,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly GetPendingPuzzleProposals $getPendingPuzzleProposals,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/{puzzleId}/navrhnout-zmeny',
            'en' => '/en/puzzle/{puzzleId}/propose-changes',
        ],
        name: 'puzzle_propose_changes',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        string $puzzleId,
    ): Response {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $puzzle = $this->getPuzzleOverview->byId($puzzleId);

        // Check for existing pending proposals
        if ($this->getPendingPuzzleProposals->hasPendingForPuzzle($puzzleId)) {
            $this->addFlash('warning', $this->translator->trans('puzzle_report.flash.pending_proposal_exists'));

            return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
        }

        // Pre-populate propose changes form with existing values
        $proposeFormData = new ProposePuzzleChangesFormData();
        $proposeFormData->name = $puzzle->puzzleName;
        $proposeFormData->manufacturerId = $puzzle->manufacturerId;
        $proposeFormData->piecesCount = $puzzle->piecesCount;
        $proposeFormData->ean = $puzzle->puzzleEan;
        $proposeFormData->identificationNumber = $puzzle->puzzleIdentificationNumber;

        $proposeForm = $this->createForm(ProposePuzzleChangesFormType::class, $proposeFormData);
        $reportForm = $this->createForm(ReportDuplicatePuzzleFormType::class, new ReportDuplicatePuzzleFormData());

        $activeTab = $request->query->getString('tab', 'propose');

        $proposeForm->handleRequest($request);
        $reportForm->handleRequest($request);

        // Handle propose changes submission
        if ($proposeForm->isSubmitted() && $proposeForm->isValid()) {
            /** @var ProposePuzzleChangesFormData $formData */
            $formData = $proposeForm->getData();

            $changeRequestId = Uuid::uuid7()->toString();

            $this->messageBus->dispatch(new SubmitPuzzleChangeRequest(
                changeRequestId: $changeRequestId,
                puzzleId: $puzzleId,
                reporterId: $loggedPlayer->playerId,
                proposedName: $formData->name,
                proposedManufacturerId: $formData->manufacturerId,
                proposedPiecesCount: $formData->piecesCount,
                proposedEan: $formData->ean,
                proposedIdentificationNumber: $formData->identificationNumber,
                proposedPhoto: $formData->photo,
            ));

            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                return $this->render('puzzle-report/_stream.html.twig', [
                    'puzzle_id' => $puzzleId,
                    'message' => $this->translator->trans('puzzle_report.flash.changes_submitted'),
                ]);
            }

            $this->addFlash('success', $this->translator->trans('puzzle_report.flash.changes_submitted'));

            return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
        }

        // Handle report duplicate submission
        if ($reportForm->isSubmitted() && $reportForm->isValid()) {
            /** @var ReportDuplicatePuzzleFormData $formData */
            $formData = $reportForm->getData();

            // Parse URLs to extract puzzle IDs
            $duplicateIds = $this->parseDuplicatePuzzleIds($formData);

            if (count($duplicateIds) > 0) {
                $mergeRequestId = Uuid::uuid7()->toString();

                $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
                    mergeRequestId: $mergeRequestId,
                    sourcePuzzleId: $puzzleId,
                    reporterId: $loggedPlayer->playerId,
                    duplicatePuzzleIds: $duplicateIds,
                ));

                if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                    $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                    return $this->render('puzzle-report/_stream.html.twig', [
                        'puzzle_id' => $puzzleId,
                        'message' => $this->translator->trans('puzzle_report.flash.duplicate_reported'),
                    ]);
                }

                $this->addFlash('success', $this->translator->trans('puzzle_report.flash.duplicate_reported'));

                return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
            }

            $activeTab = 'report';
        }

        $templateParams = [
            'puzzle' => $puzzle,
            'propose_form' => $proposeForm,
            'report_form' => $reportForm,
            'puzzle_id' => $puzzleId,
            'active_tab' => $activeTab,
        ];

        // Determine if form has validation errors (for proper Turbo handling)
        $hasErrors = ($proposeForm->isSubmitted() && !$proposeForm->isValid())
            || ($reportForm->isSubmitted() && !$reportForm->isValid());

        $statusCode = $hasErrors ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        // Turbo Frame request - return frame content only
        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('puzzle-report/modal.html.twig', $templateParams, new Response('', $statusCode));
        }

        // Non-Turbo request: return full page for progressive enhancement
        return $this->render('puzzle-report/propose_changes.html.twig', $templateParams, new Response('', $statusCode));
    }

    /**
     * @return array<string>
     */
    private function parseDuplicatePuzzleIds(ReportDuplicatePuzzleFormData $formData): array
    {
        $ids = $formData->duplicatePuzzleIds;

        // Add puzzle ID from dropdown selection
        if ($formData->selectedPuzzleId !== null && $formData->selectedPuzzleId !== '') {
            if (Uuid::isValid($formData->selectedPuzzleId)) {
                $ids[] = $formData->selectedPuzzleId;
            }
        }

        // Parse URL from single text input
        if ($formData->duplicatePuzzleUrl !== null && $formData->duplicatePuzzleUrl !== '') {
            $line = trim($formData->duplicatePuzzleUrl);

            // Try to extract puzzle ID from URL
            if (preg_match('/puzzle\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $line, $matches)) {
                $ids[] = $matches[1];
            } elseif (Uuid::isValid($line)) {
                // Direct UUID
                $ids[] = $line;
            }
        }

        return array_values(array_unique($ids));
    }
}
