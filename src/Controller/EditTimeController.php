<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Security\Core\User\UserInterface;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\FirstTryAlreadyTaken;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\FormData\EditPuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\FormType\EditPuzzleSolvingTimeFormType;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\RecordDuplicatePrevention;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetXpEntriesForSolve;
use SpeedPuzzling\Web\Results\SolvedPuzzleDetail;
use SpeedPuzzling\Web\Services\CoPuzzlerPicker;
use SpeedPuzzling\Web\Services\FirstTry\FirstTryFormCheck;
use SpeedPuzzling\Web\Services\PhotoStash\FormPhotoStash;
use SpeedPuzzling\Web\Services\PuzzleChoicesBuilder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Services\SecretPuzzleRefusalMessage;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeFormCheck;
use SpeedPuzzling\Web\Value\CompetitionPick;
use SpeedPuzzling\Web\Value\DuplicatePreventionKind;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\EditTimeReturnContext;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use SpeedPuzzling\Web\Value\PuzzleAddMode;
use SpeedPuzzling\Web\Value\ResultEntryCheck;
use SpeedPuzzling\Web\Value\SolvingTime;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EditTimeController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private TranslatorInterface $translator,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private CoPuzzlerPicker $coPuzzlerPicker,
        readonly private FirstTryFormCheck $firstTryFormCheck,
        readonly private FormPhotoStash $formPhotoStash,
        readonly private PuzzleChoicesBuilder $puzzleChoicesBuilder,
        readonly private SecretPuzzleAccess $secretPuzzleAccess,
        readonly private SecretPuzzleRefusalMessage $secretPuzzleRefusalMessage,
        readonly private SuspiciousTimeFormCheck $suspiciousTimeFormCheck,
        readonly private GetXpEntriesForSolve $getXpEntriesForSolve,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/upravit-cas/{timeId}',
            'en' => '/en/edit-time/{timeId}',
            'es' => '/es/editar-tiempo/{timeId}',
            'ja' => '/ja/時間編集/{timeId}',
            'fr' => '/fr/modifier-temps/{timeId}',
            'de' => '/de/zeit-bearbeiten/{timeId}',
        ],
        name: 'edit_time',
    )]
    public function __invoke(Request $request, #[CurrentUser] UserInterface $user, string $timeId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $solvedPuzzle = $this->getPlayerSolvedPuzzles->byTimeId($timeId);

        if ($solvedPuzzle->isEditableBy($player->playerId) === false) {
            throw $this->createAccessDeniedException();
        }

        $isModalRequest = $request->headers->get('Turbo-Frame') === 'modal-frame';
        $contextValue = $request->isMethod('POST')
            ? $request->request->getString('context')
            : $request->query->getString('context');
        $context = EditTimeReturnContext::tryFrom($contextValue) ?? EditTimeReturnContext::Profile;

        $data = new EditPuzzleSolvingTimeFormData();

        // Detect initial mode based on whether time exists
        $initialMode = $solvedPuzzle->time !== null ? 'speed_puzzling' : 'relax';
        $data->mode = $solvedPuzzle->time !== null ? PuzzleAddMode::SpeedPuzzling : PuzzleAddMode::Relax;

        if ($solvedPuzzle->time !== null) {
            $data->setTimeFromSeconds($solvedPuzzle->time);
        }

        // "Fix the time" of a result awaiting verification brings the time its reason suggests - shown on opening only,
        // never over what the player submitted, never for another time than it was made for
        // (docs/features/suspicious-time-review.md, "Where they see it")
        $suggestedSeconds = self::suggestedSeconds($request, $solvedPuzzle->time);

        if ($suggestedSeconds !== null && $solvedPuzzle->time !== null) {
            $data->setTimeFromSeconds($suggestedSeconds);
        }

        $data->comment = $solvedPuzzle->comment;
        $data->finishedAt = $solvedPuzzle->finishedAt;
        $data->puzzle = $solvedPuzzle->puzzleId;
        $data->brand = $solvedPuzzle->manufacturerId;
        $data->firstAttempt = $solvedPuzzle->firstAttempt;
        $data->unboxed = $solvedPuzzle->unboxed;

        // Its link as the picker shows it: a series pick as the series (the edition MySpeedPuzzling found is only named
        // by the preview), an explicit edition as `edition:<uuid>`, a one-time event as itself
        // (docs/features/events-page/high-frequency-series.md "Validation, submit, prefill")
        $currentCompetitionPick = CompetitionPick::ofTime($solvedPuzzle->competitionId, $solvedPuzzle->seriesPickId, $solvedPuzzle->competitionIsEdition);
        $data->competition = $currentCompetitionPick?->fieldValue();

        $groupPlayers = [];
        foreach ($solvedPuzzle->players ?? [] as $groupPlayer) {
            $groupPlayers[] = $groupPlayer->playerCode ? "#$groupPlayer->playerCode" : $groupPlayer->playerName ?? '';
        }

        if ($request->request->has('group_players')) {
            /** @var array<string> $groupPlayers */
            $groupPlayers = $request->request->all('group_players');
        }

        $isGroupPuzzlersValid = true;
        foreach ($groupPlayers as $groupPlayer) {
            if (trim($groupPlayer) === '') {
                $isGroupPuzzlersValid = false;
                break;
            }
        }

        // Only whoever tracked the result may move it to another puzzle (docs/features/duplicate-results.md, Layer 4)
        $canChangePuzzle = $solvedPuzzle->playerId === $player->playerId;
        $storedPuzzle = $this->getPuzzleOverview->byId($solvedPuzzle->puzzleId);

        $editTimeForm = $this->createForm(EditPuzzleSolvingTimeFormType::class, $data, [
            // Server-derived from the access-checked row, never from the request: the picker must
            // keep offering the linked competition, series or edition even when it is not publicly selectable
            'current_competition_pick' => $currentCompetitionPick,
            'submitted_competition_pick' => $this->submittedCompetitionPick($request),
            'can_change_puzzle' => $canChangePuzzle,
        ]);
        // A photo kept from a refused submit goes back into its empty file input first (FormPhotoStash)
        $restoredPhotos = $this->formPhotoStash->restore($request, $editTimeForm, $player->playerId);
        $editTimeForm->handleRequest($request);
        $this->formPhotoStash->reportLost($editTimeForm, $restoredPhotos);

        // The puzzle the result is saved on: another one picked by the tracker, once it is known to exist
        $activePuzzle = $storedPuzzle;
        $pickedPuzzleId = $editTimeForm->get('puzzle')->getData();

        if ($editTimeForm->isSubmitted() && is_string($pickedPuzzleId) && Uuid::isValid($pickedPuzzleId) && strtolower($pickedPuzzleId) !== $solvedPuzzle->puzzleId) {
            try {
                // A puzzle a competition keeps secret: not found for anybody else (its name must not show on the
                // re-rendered form), and nothing personal before its reveal for its organisers (SecretPuzzleAccess)
                $this->secretPuzzleAccess->assertWritableByViewer($pickedPuzzleId);
                $activePuzzle = $this->getPuzzleOverview->byId($pickedPuzzleId);
            } catch (PuzzleNotFound) {
                $editTimeForm->get('puzzle')->addError(new FormError($this->translator->trans('edit_time_puzzle.choose_from_list')));
            } catch (PuzzleNotRevealedYet $refusal) {
                $editTimeForm->get('puzzle')->addError(new FormError($this->secretPuzzleRefusalMessage->notRevealedYet($refusal)));
            }
        }

        // The co-puzzler inputs live outside the Symfony form, so an empty one has to invalidate the form
        // explicitly - that is what makes render() answer 422 instead of a 200 Turbo Drive discards
        if ($isGroupPuzzlersValid === false && $editTimeForm->isSubmitted()) {
            $editTimeForm->addError(new FormError($this->translator->trans('forms.empty_group_player')));
        }

        // docs/features/first-try-integrity.md + docs/features/duplicate-results.md (Layer 2)
        $firstTryResolution = FirstTryResolution::tryFrom($request->request->getString('first_try_resolution')) ?? FirstTryResolution::None;
        $duplicateConfirmed = $request->request->getString('duplicate_confirmed') === '1';
        $check = ResultEntryCheck::nothing();

        // Also when the form opens with the tag: an old duplicate gets its pointer to the conflicts page right away
        if ($editTimeForm->isSubmitted() || $data->firstAttempt) {
            $check = $this->firstTryFormCheck->forEditedResult(
                $player->playerId,
                $solvedPuzzle,
                $groupPlayers,
                $data->finishedAt,
                $data->firstAttempt,
                // Relax has no time to compare
                $data->mode === PuzzleAddMode::SpeedPuzzling
                    ? SolvingTime::fromHoursMinutesSeconds($data->timeHours, $data->timeMinutes, $data->timeSeconds)->seconds
                    : null,
                $activePuzzle->puzzleId,
            );

            if ($editTimeForm->isSubmitted() && $check->duplicateBlocks($duplicateConfirmed)) {
                $editTimeForm->addError(new FormError($this->translator->trans('duplicate_check.form_error')));

                $this->messageBus->dispatch(new RecordDuplicatePrevention(
                    playerId: $player->playerId,
                    kind: DuplicatePreventionKind::WarningShown,
                    timeId: $solvedPuzzle->timeId,
                    puzzleId: $activePuzzle->puzzleId,
                    via: SolvingTimeSource::Form,
                ));
            } elseif ($editTimeForm->isSubmitted() && $check->firstTryBlocks($firstTryResolution, $duplicateConfirmed)) {
                $editTimeForm->addError(new FormError($this->translator->trans('first_try.form_error')));
            }
        }

        // Time verification, the form's question (docs/features/suspicious-time-review.md, "Catch it while typing"): a
        // changed time, puzzle or group far off the tracker's own times is saved only after "Yes, it's right" - asked
        // of the tracker only; an entry left as it is was judged when it was saved, a failed check judges nothing.
        // pace_confirmed = the key of the values "Yes, it's right" was chosen for - stale once the form holds others
        $paceAnswer = $request->request->getString('pace_confirmed');
        $paceConfirmed = false;
        $paceCheck = null;

        if ($editTimeForm->isSubmitted() && $data->mode === PuzzleAddMode::SpeedPuzzling) {
            $paceCheck = $this->suspiciousTimeFormCheck->forEditedResult(
                $player->playerId,
                $player->code,
                $solvedPuzzle,
                $groupPlayers,
                $data->finishedAt,
                SolvingTime::fromHoursMinutesSeconds($data->timeHours, $data->timeMinutes, $data->timeSeconds)->seconds,
                $activePuzzle->puzzleId,
            );

            $paceConfirmed = $paceCheck?->isConfirmedBy($paceAnswer) === true;

            if ($paceCheck?->isRaised() === true && $paceConfirmed === false) {
                $editTimeForm->addError(new FormError($this->translator->trans('suspicious_time.form.error')));
            }
        }

        $firstTry = $check->firstTry;

        if ($editTimeForm->isSubmitted() && $editTimeForm->isValid()) {
            if ($data->mode === PuzzleAddMode::Relax && $request->request->has('no_remember_date')) {
                $data->finishedAt = null;
            }

            try {
                $this->messageBus->dispatch(
                    EditPuzzleSolvingTime::fromFormData(
                        $user->getUserIdentifier(),
                        $timeId,
                        $groupPlayers,
                        $data,
                        $request->request->getString('team_name'),
                        $firstTryResolution,
                        // Only an answer to a same-day twin the check found counts as "saved anyway"
                        $duplicateConfirmed && $check->duplicates?->needsConfirmation() === true,
                        // Only an answer to a time the check raised is a confirmation
                        $paceCheck !== null && $paceConfirmed ? SuspiciousTimeFormCheck::confirmedExpectation($paceCheck->assessment) : null,
                    ),
                );

                $this->formPhotoStash->forget($restoredPhotos, $player->playerId);

                $this->addFlash('success', $this->translator->trans('flashes.time_edited'));

                if ($isModalRequest && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                    $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                    return $this->render('edit-time_success_stream.html.twig');
                }

                return $this->redirect($this->resolveReturnUrl($context, $solvedPuzzle));
            } catch (HandlerFailedException $exception) {
                $realException = $exception->getPrevious();

                if ($realException instanceof FirstTryAlreadyTaken) {
                    // Somebody saved a first try in the meantime - the notice explains what the handler saw
                    $editTimeForm->addError(new FormError($this->translator->trans('first_try.form_error')));
                    $firstTry = $realException->assessment;
                } elseif ($realException instanceof CanNotAssembleEmptyGroup) {
                    $editTimeForm->addError(new FormError($this->translator->trans('forms.empty_group_error')));
                } elseif ($realException instanceof SuspiciousPpm) {
                    $editTimeForm->addError(new FormError($this->translator->trans('forms.too_high_ppm')));
                } else {
                    throw $exception;
                }
            }
        }

        $templateParams = [
            'active_puzzle' => $activePuzzle,
            'can_change_puzzle' => $canChangePuzzle,
            // The picker lists no secret puzzle - a result already on one keeps it selectable
            'own_puzzle' => $canChangePuzzle ? [
                'brand' => $storedPuzzle->manufacturerId,
                'option' => $this->puzzleChoicesBuilder->build([$storedPuzzle], $request->getLocale())[0],
            ] : null,
            'solved_puzzle' => $solvedPuzzle,
            'solving_time_form' => $editTimeForm,
            'suggested_seconds' => $solvedPuzzle->time !== null ? $suggestedSeconds : null,
            'filled_group_players' => $groupPlayers,
            'selected_add_puzzle' => false,
            'selected_add_manufacturer' => false,
            'active_stopwatch' => null,
            // Only the old co-puzzler rows list favorites up front; the picker fetches its suggestions on demand
            'favorite_players' => $this->coPuzzlerPicker->isEnabled() ? [] : $this->getFavoritePlayers->forPlayerId($player->playerId),
            'copuzzler_picker_enabled' => $this->coPuzzlerPicker->isEnabled(),
            // Somebody else's time: whoever tracked it stays in the group, shown as a chip that cannot be removed
            'copuzzler_picker' => $this->coPuzzlerPicker->formState(
                $groupPlayers,
                $solvedPuzzle->playerId === $player->playerId ? null : $solvedPuzzle->playerId,
                $player->playerId,
            ),
            'filled_team_name' => $request->request->getString('team_name'),
            'initial_mode' => $initialMode,
            'return_context' => $context->value,
            'return_url' => $this->resolveReturnUrl($context, $solvedPuzzle),
            'return_title' => $this->resolveReturnTitle($context, $solvedPuzzle),
            'first_try' => $firstTry,
            'first_try_resolution' => $firstTryResolution->value,
            'duplicates' => $check->duplicates,
            'duplicate_confirmed' => $duplicateConfirmed,
            'pace_check' => $paceCheck,
            'pace_confirmed' => $paceConfirmed,
            'kept_photos' => $this->formPhotoStash->keep($editTimeForm, $restoredPhotos, $player->playerId),
            // Delete dialog warning: how much XP disappears with this solve (0 = hide line).
            'xp_delete_warning' => $this->xpFeatureGate->isVisibleFor($player)
                ? max($this->getXpEntriesForSolve->totalForSolvingTime($timeId), 0)
                : 0,
        ];

        if ($isModalRequest) {
            return $this->render('edit-time_modal.html.twig', $templateParams);
        }

        return $this->render('edit-time.html.twig', $templateParams);
    }

    /**
     * The "Competition / event" value a submit holds, read before the form is built: an edition picked by typing or
     * from the short list is offered again on a refused submit (only while publicly visible - GetSelectableCompetitions)
     */
    private function submittedCompetitionPick(Request $request): null|CompetitionPick
    {
        if ($request->isMethod('POST') === false) {
            return null;
        }

        $form = $request->request->all()['edit_puzzle_solving_time_form'] ?? null;
        $submitted = is_array($form) ? ($form['competition'] ?? null) : null;

        return is_string($submitted) ? CompetitionPick::tryFrom($submitted) : null;
    }

    private function resolveReturnUrl(EditTimeReturnContext $context, SolvedPuzzleDetail $solvedPuzzle): string
    {
        return match ($context) {
            EditTimeReturnContext::PuzzleDetail => $this->generateUrl('puzzle_detail', ['puzzleId' => $solvedPuzzle->puzzleId]),
            EditTimeReturnContext::TimeRecap => $this->generateUrl('added_time_recap', ['timeId' => $solvedPuzzle->timeId]),
            EditTimeReturnContext::Profile => $this->generateUrl('my_profile'),
            EditTimeReturnContext::ReviewResults => $this->generateUrl('review_results') . '#awaiting-verification',
        };
    }

    private function resolveReturnTitle(EditTimeReturnContext $context, SolvedPuzzleDetail $solvedPuzzle): string
    {
        return match ($context) {
            EditTimeReturnContext::PuzzleDetail => $solvedPuzzle->puzzleName,
            EditTimeReturnContext::TimeRecap => $this->translator->trans('added_time_recap.title'),
            EditTimeReturnContext::Profile => $this->translator->trans('my_profile.title'),
            EditTimeReturnContext::ReviewResults => $this->translator->trans('review_results.page.title'),
        };
    }

    /**
     * `?suggested_seconds=` of a GET - a positive whole number of seconds, anything else is ignored - made for the
     * time `?suggested_for=`: only while the result still holds that time (a review page opened before an edit links
     * a suggestion for the time before it).
     */
    private static function suggestedSeconds(Request $request, null|int $currentSeconds): null|int
    {
        $value = $request->isMethod('GET') ? $request->query->getString('suggested_seconds') : '';
        $for = $request->isMethod('GET') ? $request->query->getString('suggested_for') : '';

        if (preg_match('/^[1-9][0-9]{0,6}$/', $value) !== 1 || $currentSeconds === null || $for !== (string) $currentSeconds) {
            return null;
        }

        return (int) $value;
    }
}
