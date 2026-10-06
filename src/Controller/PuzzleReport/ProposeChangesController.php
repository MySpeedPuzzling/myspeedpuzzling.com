<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PuzzleReport;

use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\FormData\ProposePuzzleChangesFormData;
use SpeedPuzzling\Web\FormData\PuzzleNamesFormData;
use SpeedPuzzling\Web\FormData\ReportDuplicatePuzzleFormData;
use SpeedPuzzling\Web\FormType\ProposePuzzleChangesFormType;
use SpeedPuzzling\Web\FormType\ReportDuplicatePuzzleFormType;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Query\GetPendingPuzzleProposals;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleRecord;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
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
        private readonly GetPuzzleRecord $getPuzzleRecord,
        private readonly SecretPuzzleAccess $secretPuzzleAccess,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/{puzzleId}/navrhnout-zmenu',
            'en' => '/en/puzzle/{puzzleId}/suggest-change',
            'es' => '/es/puzzle/{puzzleId}/sugerir-cambio',
            'ja' => '/ja/puzzle/{puzzleId}/suggest-change',
            'fr' => '/fr/puzzle/{puzzleId}/suggerer-modification',
            'de' => '/de/puzzle/{puzzleId}/aenderung-vorschlagen',
        ],
        name: 'puzzle_suggest_change',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(
        Request $request,
        string $puzzleId,
    ): Response {
        // A puzzle a competition keeps secret answers 404 to everybody but its organisers (SecretPuzzleAccess)
        $this->secretPuzzleAccess->assertVisible($puzzleId, alsoWhileImageHidden: true);

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $puzzle = $this->getPuzzleOverview->byId($puzzleId);

        // One proposal at a time (GetPendingPuzzleProposals) - the pending ones are shown instead of the form. Names only
        // are proposed meanwhile too: they apply as a diff, so a submitted one is filed even when a proposal is waiting
        $blocked = $this->getPendingPuzzleProposals->blocksNewProposal($puzzleId);

        if ($blocked && $request->isMethod('POST') === false) {
            return $this->pendingProposals($request, $puzzle);
        }

        // Every name with its language - the overview has the names, not the main title's language
        $record = $this->getPuzzleRecord->byId($puzzleId) ?? throw new PuzzleNotFound();

        // Pre-populate propose changes form with existing values
        $proposeFormData = new ProposePuzzleChangesFormData();
        $proposeFormData->names = PuzzleNamesFormData::fromNames($record->name, $record->nameLanguage, $record->alternativeNames);
        $proposeFormData->recordVersion = $record->recordVersion();
        $proposeFormData->brand = $puzzle->manufacturerId;
        $proposeFormData->piecesCount = $puzzle->piecesCount;
        $proposeFormData->loadPuzzleCodes($puzzle->puzzleEan, $puzzle->puzzleIdentificationNumber);

        $proposeForm = $this->createForm(ProposePuzzleChangesFormType::class, $proposeFormData);

        // Create report form for display (handled by ReportDuplicatePuzzleController on POST)
        $reportForm = $this->createForm(ReportDuplicatePuzzleFormType::class, new ReportDuplicatePuzzleFormData());

        $activeTab = $request->query->getString('tab', 'propose');

        $proposeForm->handleRequest($request);

        // The proposal is compared with - and its names applied as a diff against - the puzzle the player saw. When it
        // changed since (a form without a version comes from the release before), the player looks at it again
        if ($proposeForm->isSubmitted() && $proposeForm->isValid() && $proposeFormData->recordVersion !== $record->recordVersion()) {
            $proposeForm->addError(new FormError($this->translator->trans('puzzle_names.record_changed_meanwhile')));
        }

        // Handle propose changes submission
        if ($proposeForm->isSubmitted() && $proposeForm->isValid()) {
            /** @var ProposePuzzleChangesFormData $formData */
            $formData = $proposeForm->getData();

            $proposedName = $formData->names->mainTitle();

            // The other names as the puzzle would keep them next to the proposed main title, and its language
            $namesChanged = $formData->names->nameLanguage !== $record->nameLanguage
                || $formData->names->toPuzzleNames()->cleanedFor($proposedName)->diff($record->alternativeNames)->isEmpty() === false;

            // The codes compared in their canonical form - the inputs show them as displayed (a UPC with its 12th digit)
            $otherChanges = $formData->brand !== $puzzle->manufacturerId
                || $formData->piecesCount !== $puzzle->piecesCount
                || $formData->eanList()->toStored() !== EanList::fromStored($puzzle->puzzleEan)->toStored()
                || $formData->brandCodeList()->toStored() !== BrandCodeList::fromStored($puzzle->puzzleIdentificationNumber)->toStored()
                || $formData->photo !== null;

            // Check if any values actually changed
            $hasChanges = $proposedName !== $record->name || $namesChanged || $otherChanges;

            // A proposal filed since the form was opened waits for a moderator - names only may still be proposed
            if ($blocked && $otherChanges) {
                return $this->pendingProposals($request, $puzzle);
            }

            if (!$hasChanges) {
                $warningMessage = $this->translator->trans('puzzle_report.flash.no_changes');

                if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                    $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                    return $this->render('puzzle-report/_stream.html.twig', [
                        'puzzle_id' => $puzzleId,
                        'message' => $warningMessage,
                        'type' => 'warning',
                    ]);
                }

                $this->addFlash('warning', $warningMessage);

                return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
            }

            $changeRequestId = Uuid::uuid7()->toString();

            $this->messageBus->dispatch(new SubmitPuzzleChangeRequest(
                changeRequestId: $changeRequestId,
                puzzleId: $puzzleId,
                reporterId: $loggedPlayer->playerId,
                proposedName: $proposedName,
                proposedBrand: $formData->brand,
                proposedPiecesCount: $formData->piecesCount,
                proposedEans: $formData->eanList(),
                proposedBrandCodes: $formData->brandCodeList(),
                proposedPhoto: $formData->photo,
                // What the player saw - the record version above equals it
                originalAlternativeNames: $record->alternativeNames,
                originalNameLanguage: $record->nameLanguage,
                proposedAlternativeNames: $namesChanged ? $formData->names->toPuzzleNames() : null,
                proposedNameLanguage: $formData->names->nameLanguage,
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

    /**
     * The proposals waiting for a moderator, instead of the form
     */
    private function pendingProposals(Request $request, PuzzleOverview $puzzle): Response
    {
        // Handle Turbo Frame request - show pending proposals modal
        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('puzzle-report/pending_proposals_modal.html.twig', [
                'puzzle' => $puzzle,
                'proposals' => $this->getPendingPuzzleProposals->forPuzzle($puzzle->puzzleId),
            ]);
        }

        $this->addFlash('warning', $this->translator->trans('puzzle_report.flash.pending_proposal_exists'));

        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzle->puzzleId]);
    }
}
