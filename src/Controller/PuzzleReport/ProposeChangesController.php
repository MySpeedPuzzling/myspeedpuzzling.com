<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PuzzleReport;

use SpeedPuzzling\Web\Services\PhotoStash\FormPhotoStash;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\FormData\ProposePuzzleChangesFormData;
use SpeedPuzzling\Web\FormData\ReportDuplicatePuzzleFormData;
use SpeedPuzzling\Web\FormType\ProposePuzzleChangesFormType;
use SpeedPuzzling\Web\FormType\ReportDuplicatePuzzleFormType;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Query\GetPendingPuzzleProposals;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleRecord;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Results\PuzzleRecord;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleProposalField;
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
        private readonly FormPhotoStash $formPhotoStash,
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

        // A field a pending proposal changes waits for its review (GetPendingPuzzleProposals::locks()) - the form shows
        // it locked, the others stay editable. Only when nothing but the names is left (or a merge request waits) the
        // pending proposals are shown instead of the form. Names are proposed meanwhile too: they apply as a diff, so a
        // submitted one is filed even then
        $locks = $this->getPendingPuzzleProposals->locks($puzzleId);

        if ($locks->locksEveryField() && $request->isMethod('POST') === false) {
            return $this->pendingProposals($request, $puzzle);
        }

        // Every name with its language - the overview has the names, not the main title's language
        $record = $this->getPuzzleRecord->byId($puzzleId) ?? throw new PuzzleNotFound();

        $proposeFormData = ProposePuzzleChangesFormData::forPuzzle($record, $puzzle);

        $proposeForm = $this->createForm(ProposePuzzleChangesFormType::class, $proposeFormData);

        // Create report form for display (handled by ReportDuplicatePuzzleController on POST)
        $reportForm = $this->createForm(ReportDuplicatePuzzleFormType::class, new ReportDuplicatePuzzleFormData());

        $activeTab = $request->query->getString('tab', 'propose');

        // A photo kept from a refused submit comes back as an upload (FormPhotoStash)
        $restoredPhotos = $this->formPhotoStash->restore($request, $proposeForm, $loggedPlayer->playerId);
        $proposeForm->handleRequest($request);
        $this->formPhotoStash->reportLost($proposeForm, $restoredPhotos);

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
            $changedFields = array_keys(array_filter([
                PuzzleProposalField::Manufacturer->value => $formData->brand !== $puzzle->manufacturerId,
                PuzzleProposalField::PiecesCount->value => $formData->piecesCount !== $puzzle->piecesCount,
                PuzzleProposalField::Ean->value => $formData->eanList()->toStored() !== EanList::fromStored($puzzle->puzzleEan)->toStored(),
                PuzzleProposalField::IdentificationNumber->value => $formData->brandCodeList()->toStored() !== BrandCodeList::fromStored($puzzle->puzzleIdentificationNumber)->toStored(),
                PuzzleProposalField::Image->value => $formData->photo !== null,
            ]));
            $changedFields = array_map(PuzzleProposalField::from(...), $changedFields);
            $otherChanges = $changedFields !== [];

            // Check if any values actually changed
            $hasChanges = $proposedName !== $record->name || $namesChanged || $otherChanges;

            // A field another pending proposal changes (filed since the form was opened, or a locked field sent anyway)
            // waits for its review. The form comes back with everything else typed (the photo kept) and the locked
            // fields shown as the puzzle has them, so sending it again files the rest
            $refusedFields = $locks->lockedAmong($changedFields);

            if ($refusedFields !== []) {
                $proposeForm->addError(new FormError($this->translator->trans('puzzle_report.flash.pending_proposal_fields', [
                    '%fields%' => implode(', ', array_map(
                        fn (PuzzleProposalField $field): string => $this->translator->trans($field->formLabel()),
                        $refusedFields,
                    )),
                ])));
            } else {
                return $this->submit($request, $formData, $puzzleId, $loggedPlayer->playerId, $record, $hasChanges, $namesChanged, $restoredPhotos);
            }
        }

        $templateParams = [
            'puzzle' => $puzzle,
            'propose_form' => $proposeForm,
            'report_form' => $reportForm,
            'puzzle_id' => $puzzleId,
            'active_tab' => $activeTab,
            'locks' => $locks,
            'kept_photos' => $this->formPhotoStash->keep($proposeForm, $restoredPhotos, $loggedPlayer->playerId),
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
     * A valid proposal: filed - or "nothing changed" when it changes nothing.
     *
     * @param array<string, null|string> $restoredPhotos
     */
    private function submit(
        Request $request,
        ProposePuzzleChangesFormData $formData,
        string $puzzleId,
        string $playerId,
        PuzzleRecord $record,
        bool $hasChanges,
        bool $namesChanged,
        array $restoredPhotos,
    ): Response {
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

        $this->messageBus->dispatch(new SubmitPuzzleChangeRequest(
            changeRequestId: Uuid::uuid7()->toString(),
            puzzleId: $puzzleId,
            reporterId: $playerId,
            proposedName: $formData->names->mainTitle(),
            proposedBrand: $formData->brand,
            proposedPiecesCount: $formData->piecesCount,
            proposedEans: $formData->eanList(),
            proposedBrandCodes: $formData->brandCodeList(),
            proposedPhoto: $formData->photo,
            // What the player saw - the record version checked before equals it
            originalAlternativeNames: $record->alternativeNames,
            originalNameLanguage: $record->nameLanguage,
            proposedAlternativeNames: $namesChanged ? $formData->names->toPuzzleNames() : null,
            proposedNameLanguage: $formData->names->nameLanguage,
        ));

        $this->formPhotoStash->forget($restoredPhotos, $playerId);

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('puzzle-report/_stream.html.twig', [
                'puzzle_id' => $puzzleId,
                'message' => $this->translator->trans('puzzle_report.flash.changes_submitted'),
                'mark_improvement_row' => true,
                'proposed_ean' => $formData->eanList()->isEmpty() === false,
                'proposed_image' => $formData->photo !== null,
            ]);
        }

        $this->addFlash('success', $this->translator->trans('puzzle_report.flash.changes_submitted'));

        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
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
