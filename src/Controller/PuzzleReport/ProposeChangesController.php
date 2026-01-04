<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PuzzleReport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\ProposePuzzleChangesFormData;
use SpeedPuzzling\Web\FormData\ReportDuplicatePuzzleFormData;
use SpeedPuzzling\Web\FormType\ProposePuzzleChangesFormType;
use SpeedPuzzling\Web\FormType\ReportDuplicatePuzzleFormType;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
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
            'es' => '/es/puzzle/{puzzleId}/proponer-cambios',
            'ja' => '/ja/puzzle/{puzzleId}/propose-changes',
            'fr' => '/fr/puzzle/{puzzleId}/proposer-changements',
            'de' => '/de/puzzle/{puzzleId}/aenderungen-vorschlagen',
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

        // Check for existing pending proposals - show them instead of the form
        if ($this->getPendingPuzzleProposals->hasPendingForPuzzle($puzzleId)) {
            $proposals = $this->getPendingPuzzleProposals->forPuzzle($puzzleId);

            // Handle Turbo Frame request - show pending proposals modal
            if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
                return $this->render('puzzle-report/pending_proposals_modal.html.twig', [
                    'puzzle' => $puzzle,
                    'proposals' => $proposals,
                ]);
            }

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

        // Create report form for display (handled by ReportDuplicatePuzzleController on POST)
        $reportForm = $this->createForm(ReportDuplicatePuzzleFormType::class, new ReportDuplicatePuzzleFormData());

        $activeTab = $request->query->getString('tab', 'propose');

        $proposeForm->handleRequest($request);

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

        $templateParams = [
            'puzzle' => $puzzle,
            'propose_form' => $proposeForm,
            'report_form' => $reportForm,
            'puzzle_id' => $puzzleId,
            'active_tab' => $activeTab,
        ];

        // Determine if form has validation errors (for proper Turbo handling)
        $hasErrors = $proposeForm->isSubmitted() && !$proposeForm->isValid();

        $statusCode = $hasErrors ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        // Turbo Frame request - return frame content only
        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('puzzle-report/modal.html.twig', $templateParams, new Response('', $statusCode));
        }

        // Non-Turbo request: return full page for progressive enhancement
        return $this->render('puzzle-report/propose_changes.html.twig', $templateParams, new Response('', $statusCode));
    }
}
