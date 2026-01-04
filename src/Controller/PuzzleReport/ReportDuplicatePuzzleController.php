<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PuzzleReport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\ReportDuplicatePuzzleFormData;
use SpeedPuzzling\Web\FormType\ReportDuplicatePuzzleFormType;
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

final class ReportDuplicatePuzzleController extends AbstractController
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
            'cs' => '/puzzle/{puzzleId}/nahlasit-duplikat',
            'en' => '/en/puzzle/{puzzleId}/report-duplicate',
            'es' => '/es/puzzle/{puzzleId}/reportar-duplicado',
            'ja' => '/ja/puzzle/{puzzleId}/report-duplicate',
            'fr' => '/fr/puzzle/{puzzleId}/signaler-doublon',
            'de' => '/de/puzzle/{puzzleId}/duplikat-melden',
        ],
        name: 'puzzle_report_duplicate',
        methods: ['POST'],
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

        $reportForm = $this->createForm(ReportDuplicatePuzzleFormType::class, new ReportDuplicatePuzzleFormData());
        $reportForm->handleRequest($request);

        if ($reportForm->isSubmitted() && $reportForm->isValid()) {
            /** @var ReportDuplicatePuzzleFormData $formData */
            $formData = $reportForm->getData();

            // Parse URLs to extract puzzle IDs and filter out self-duplicates
            $duplicateIds = $this->parseDuplicatePuzzleIds($formData, $puzzleId);

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

            // No valid duplicates after filtering - show error
            $this->addFlash('error', $this->translator->trans('puzzle_report.flash.no_valid_duplicates'));
        }

        // On validation error, redirect back to the propose changes page with report tab active
        return $this->redirectToRoute('puzzle_propose_changes', [
            'puzzleId' => $puzzleId,
            'tab' => 'report',
        ]);
    }

    /**
     * Parse duplicate puzzle IDs from form data and filter out self-duplicates.
     *
     * @return array<string>
     */
    private function parseDuplicatePuzzleIds(ReportDuplicatePuzzleFormData $formData, string $sourcePuzzleId): array
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

        // Remove source puzzle ID (prevent self-duplicate)
        $ids = array_filter($ids, static fn(string $id): bool => $id !== $sourcePuzzleId);

        return array_values(array_unique($ids));
    }
}
