<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Services\SecretPuzzleRefusalMessage;
use Symfony\Component\Security\Core\User\UserInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\CollectionAlreadyExists;
use SpeedPuzzling\Web\Exceptions\FirstTryAlreadyTaken;
use SpeedPuzzling\Web\Exceptions\PuzzleIdTaken;
use SpeedPuzzling\Web\Exceptions\SolvingTimeAlreadySaved;
use SpeedPuzzling\Web\Exceptions\SolvingTimeIdReused;
use SpeedPuzzling\Web\Exceptions\SolvingTimeIdTaken;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\FormData\PuzzleAddFormData;
use SpeedPuzzling\Web\FormType\PuzzleAddFormType;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Message\AddPuzzleTracking;
use SpeedPuzzling\Web\Message\CreateCollection;
use SpeedPuzzling\Web\Message\RecordDuplicatePrevention;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetStopwatch;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Services\CoPuzzlerPicker;
use SpeedPuzzling\Web\Services\FirstTry\FirstTryFormCheck;
use SpeedPuzzling\Web\Services\MistypedYearNormalizer;
use SpeedPuzzling\Web\Services\PhotoStash\FormPhotoStash;
use SpeedPuzzling\Web\Services\RoundResults\OfficialEntryTimePrefill;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\DuplicatePreventionKind;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\FirstTryAssessment;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use SpeedPuzzling\Web\Value\PuzzleAddMode;
use SpeedPuzzling\Web\Value\ResultEntryCheck;
use SpeedPuzzling\Web\Value\SolvingTime;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use SpeedPuzzling\Web\Value\StopwatchStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class PuzzleAddController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetStopwatch $getStopwatch,
        readonly private TranslatorInterface $translator,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private CoPuzzlerPicker $coPuzzlerPicker,
        readonly private LoggerInterface $logger,
        readonly private GetPlayerCollections $getPlayerCollections,
        readonly private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        readonly private FirstTryFormCheck $firstTryFormCheck,
        readonly private FormPhotoStash $formPhotoStash,
        readonly private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        readonly private MistypedYearNormalizer $mistypedYearNormalizer,
        readonly private ClockInterface $clock,
        readonly private SecretPuzzleAccess $secretPuzzleAccess,
        readonly private SecretPuzzleRefusalMessage $secretPuzzleRefusalMessage,
        readonly private OfficialEntryTimePrefill $officialEntryTimePrefill,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pridat-puzzle/{puzzleId}',
            'en' => '/en/puzzle-add/{puzzleId}',
            'es' => '/es/agregar-puzzle/{puzzleId}',
            'ja' => '/ja/パズル追加/{puzzleId}',
            'fr' => '/fr/ajouter-puzzle/{puzzleId}',
            'de' => '/de/puzzle-hinzufuegen/{puzzleId}',
        ],
        name: 'puzzle_add',
    )]
    #[Route(
        path: [
            'cs' => '/ulozit-stopky/{stopwatchId}',
            'en' => '/en/save-stopwatch/{stopwatchId}',
            'es' => '/es/guardar-cronometro/{stopwatchId}',
            'ja' => '/ja/ストップウォッチ保存/{stopwatchId}',
            'fr' => '/fr/sauvegarder-chronometre/{stopwatchId}',
            'de' => '/de/stoppuhr-speichern/{stopwatchId}',
        ],
        name: 'finish_stopwatch',
    )]
    public function __invoke(
        Request $request,
        #[CurrentUser] UserInterface $user,
        null|string $puzzleId = null,
        null|string $stopwatchId = null,
    ): Response {
        // A puzzle a competition keeps secret answers 404 to everybody but its organisers (SecretPuzzleAccess)
        if ($puzzleId !== null) {
            $this->secretPuzzleAccess->assertVisible($puzzleId);
        }

        $userProfile = $this->retrieveLoggedUserProfile->getProfile();
        assert($userProfile !== null);

        $activePuzzle = null;
        $activeStopwatch = null;
        $data = new PuzzleAddFormData();

        // The new result's id and a new puzzle's id travel in the form - rendered on GET, kept through a refused
        // submit - so the same form sent twice saves once (docs/features/duplicate-results.md, Layer 1)
        $timeId = $this->submittedIdOrNew($request, 'time_id');
        $newPuzzleId = $this->submittedIdOrNew($request, 'new_puzzle_id');
        $savedWithFormId = $request->isMethod('POST') ? $this->puzzleSolvingTimeRepository->findById($timeId) : null;

        if ($puzzleId !== null) {
            $activePuzzle = $this->getPuzzleOverview->byId($puzzleId);
        }

        if ($stopwatchId !== null) {
            $activeStopwatch = $this->getStopwatch->byId($stopwatchId);

            // Pre-fill time fields from stopwatch
            $totalSeconds = $activeStopwatch->totalSeconds;
            $data->timeHours = intdiv($totalSeconds, 3600);
            $data->timeMinutes = intdiv($totalSeconds % 3600, 60);
            $data->timeSeconds = $totalSeconds % 60;

            // Except the save form of this stopwatch sent again: the handler answers it with the saved result
            if (
                $activeStopwatch->status === StopwatchStatus::Finished
                && $savedWithFormId === null
            ) {
                $this->addFlash('warning', $this->translator->trans('flashes.stopwatch_already_saved'));

                return $this->redirectToRoute('my_profile');
            }

            // A stopwatch on a puzzle a competition keeps secret from this player saves without that puzzle
            if ($activeStopwatch->puzzleId !== null && $this->secretPuzzleAccess->isHiddenFromViewer($activeStopwatch->puzzleId) === false) {
                $activePuzzle = $this->getPuzzleOverview->byId($activeStopwatch->puzzleId);
            }
        }

        if ($activePuzzle !== null) {
            $data->brand = $activePuzzle->manufacturerId;
            $data->puzzle = $activePuzzle->puzzleId;
        }

        // Multiscan's "open the full form" link carries the scanned code (docs/features/multiscan/README.md §6)
        $queryEan = $request->query->getString('ean');

        if ($queryEan !== '' && $data->puzzleEans === [] && preg_match('/^\\d{8,14}$/', $queryEan) === 1) {
            $data->puzzleEans = [$queryEan];
        }

        // Handle query parameters for mode and collection pre-selection
        $initialMode = 'speed_puzzling';
        $queryMode = $request->query->getString('mode');
        $queryCollection = $request->query->getString('collection');

        if ($queryMode === 'collection') {
            $data->mode = PuzzleAddMode::Collection;
            $initialMode = 'collection';

            if ($queryCollection !== '') {
                $data->collection = $queryCollection;
            }
        } elseif ($queryMode === 'relax') {
            $data->mode = PuzzleAddMode::Relax;
            $initialMode = 'relax';
        }

        // Deep link from an event page (`?competition=<uuid>`): pre-select the competition in the picker.
        // Only a publicly visible competition is honoured — anything else is ignored silently, the form
        // simply opens without a pre-selection. On POST handleRequest() overwrites the data anyway.
        $queryCompetition = $request->query->getString('competition');

        if (
            $data->mode === PuzzleAddMode::SpeedPuzzling
            && $queryCompetition !== ''
            && Uuid::isValid($queryCompetition)
            && $this->isCompetitionPubliclyVisible->check($queryCompetition)
        ) {
            $data->competition = $queryCompetition;
        }

        // "Add to my profile" of a round's published official results (`&official_entry=<participant_round|team>:<id>`,
        // docs/features/competitions-management/official-results.md): the entry fills in the puzzle, the time, the
        // round's day and the pair/team - only when the round page offers exactly that to this player, anything else
        // is ignored silently like `?competition=`. A pre-fill only: the save runs every rule of any other time
        $officialEntry = null;

        if (
            $request->isMethod('GET')
            && $data->competition !== null
            && $activeStopwatch === null
            && $request->query->getString('official_entry') !== ''
        ) {
            $officialEntry = $this->officialEntryTimePrefill->forViewer(
                $data->competition,
                $request->query->getString('official_entry'),
                $userProfile->playerId,
                $userProfile->playerName,
            );

            // The puzzle in the URL is the entry's, or the link is not what the round page made
            if ($officialEntry !== null && $activePuzzle !== null && $activePuzzle->puzzleId !== $officialEntry->puzzleId) {
                $officialEntry = null;
            }
        }

        if ($officialEntry !== null) {
            if ($activePuzzle === null) {
                $activePuzzle = $this->getPuzzleOverview->byId($officialEntry->puzzleId);
                $data->brand = $activePuzzle->manufacturerId;
                $data->puzzle = $activePuzzle->puzzleId;
            }

            $data->timeHours = $officialEntry->hours();
            $data->timeMinutes = $officialEntry->minutes();
            $data->timeSeconds = $officialEntry->secondsPart();
            $data->finishedAt = $officialEntry->finishedAt;
        }

        // Get player collections for form options (include system collection)
        $hasActiveMembership = $userProfile->activeMembership;
        $collections = [];
        $systemCollectionName = $this->translator->trans('collections.system_name');
        $collections[$systemCollectionName] = Collection::SYSTEM_ID;

        foreach ($this->getPlayerCollections->byPlayerId($userProfile->playerId) as $collection) {
            if ($collection->collectionId !== null) {
                $collections[$collection->name] = $collection->collectionId;
            }
        }

        // For non-members, force system collection
        if ($hasActiveMembership === false) {
            $data->collection = Collection::SYSTEM_ID;
        } elseif (count($collections) === 1 && $data->collection === null) {
            // Pre-fill if only one collection is available (saves a click)
            $data->collection = array_first($collections);
        }

        /** @var array<string> $groupPlayers */
        $groupPlayers = $request->request->all('group_players');

        // "Add time" of the Pairs & teams page: the form opens with that pair/team already chosen
        if ($request->isMethod('GET') && $request->query->getString('team') !== '') {
            $groupPlayers = $this->coPuzzlerPicker->groupPlayersOfTeam($request->query->getString('team'), $userProfile->playerId);
        }

        // Like the co-puzzlers, a plain field next to the Symfony form - see _copuzzler_picker.html.twig
        $teamName = $request->request->getString('team_name');

        // "Add to my profile" of an official pair/team result: its people, and its name when the form may still set it
        if ($officialEntry !== null) {
            $groupPlayers = $officialEntry->groupPlayers;
            $teamName = $officialEntry->teamName ?? '';
        }

        $isGroupPuzzlersValid = true;
        foreach ($groupPlayers as $groupPlayer) {
            if (trim($groupPlayer) === '') {
                $isGroupPuzzlersValid = false;
                break;
            }
        }

        $addTimeForm = $this->createForm(PuzzleAddFormType::class, $data, [
            'collections' => $collections,
            'has_active_membership' => $hasActiveMembership,
        ]);
        // A photo kept from a refused submit goes back into its empty file input first (FormPhotoStash)
        $restoredPhotos = $this->formPhotoStash->restore($request, $addTimeForm, $userProfile->playerId);
        $addTimeForm->handleRequest($request);
        $this->formPhotoStash->reportLost($addTimeForm, $restoredPhotos);

        // The co-puzzler inputs live outside the Symfony form, so an empty one has to invalidate the form
        // explicitly - that is what makes render() answer 422. As a skipped `if` it answered 200 with a
        // valid form, which Turbo Drive discards: the visitor clicked save and nothing happened at all.
        // Collection mode hides the co-puzzlers (their inputs are still posted) and never uses them.
        if ($isGroupPuzzlersValid === false && $addTimeForm->isSubmitted() && $data->mode !== PuzzleAddMode::Collection) {
            $addTimeForm->addError(new FormError($this->translator->trans('forms.empty_group_player')));
        }

        // The form's id belongs to a saved result: the same form sent again only when it is the same entry (puzzle,
        // time, day) - the handler answers that one. A changed entry is a new result (the player went back and
        // corrected the time), so it gets an id of its own and goes through every check like any other
        if (
            $savedWithFormId !== null
            && $addTimeForm->isSubmitted()
            && $data->mode !== PuzzleAddMode::Collection
            && $savedWithFormId->player->id->toString() === $userProfile->playerId
            && $this->isSameEntry($savedWithFormId, $data, $newPuzzleId, $request) === false
        ) {
            // A stopwatch saves one result - its save form changed after the save is no second one
            if ($activeStopwatch?->status === StopwatchStatus::Finished) {
                $this->addFlash('warning', $this->translator->trans('flashes.stopwatch_already_saved'));

                return $this->redirectToRoute('my_profile');
            }

            $timeId = Uuid::uuid7();

            // The new puzzle of the form is that result's puzzle now - a different one entered needs its own id
            if (
                $savedWithFormId->puzzle->id->equals($newPuzzleId)
                && is_string($data->puzzle)
                && Uuid::isValid($data->puzzle) === false
                && (trim($data->puzzle) !== $savedWithFormId->puzzle->name || $data->puzzlePiecesCount !== $savedWithFormId->puzzle->piecesCount)
            ) {
                $newPuzzleId = Uuid::uuid7();
            }
        }

        // A secret competition puzzle takes nothing personal before its reveal - not even from its organisers, who see
        // it: told up front, and a submit is refused on the form with everything typed and its photos kept
        // (SecretPuzzleAccess; whoever may not see it got a 404 above, or gets one from the handler)
        $secretPuzzleNotice = null;
        $chosenPuzzleId = is_string($data->puzzle) && Uuid::isValid($data->puzzle) ? $data->puzzle : $activePuzzle?->puzzleId;

        if ($chosenPuzzleId !== null) {
            try {
                $this->secretPuzzleAccess->assertWritableByViewer($chosenPuzzleId);
            } catch (PuzzleNotRevealedYet $refusal) {
                $secretPuzzleNotice = $this->secretPuzzleRefusalMessage->timesAfterReveal($refusal);

                if ($addTimeForm->isSubmitted()) {
                    $addTimeForm->get('puzzle')->addError(new FormError($this->secretPuzzleRefusalMessage->notRevealedYet($refusal)));
                }
            }
        }

        // Checked before anything is dispatched - a refused save must not leave a new puzzle behind. A new puzzle
        // has no history yet (docs/features/first-try-integrity.md, docs/features/duplicate-results.md Layer 2)
        $firstTryResolution = FirstTryResolution::tryFrom($request->request->getString('first_try_resolution')) ?? FirstTryResolution::None;
        $duplicateConfirmed = $request->request->getString('duplicate_confirmed') === '1';
        $check = ResultEntryCheck::nothing();

        if (
            $addTimeForm->isSubmitted()
            && $data->mode === PuzzleAddMode::SpeedPuzzling
            && is_string($data->puzzle)
            && Uuid::isValid($data->puzzle)
        ) {
            $check = $this->firstTryFormCheck->forNewResult(
                $userProfile->playerId,
                $data->puzzle,
                $groupPlayers,
                $data->finishedAt,
                $data->firstAttempt,
                SolvingTime::fromHoursMinutesSeconds($data->timeHours, $data->timeMinutes, $data->timeSeconds)->seconds,
                $timeId->toString(),
            );

            // The same time from the same day first: a copy of a first try is no case for "make this my first try"
            if ($check->duplicateBlocks($duplicateConfirmed)) {
                $addTimeForm->addError(new FormError($this->translator->trans('duplicate_check.form_error')));

                $this->messageBus->dispatch(new RecordDuplicatePrevention(
                    playerId: $userProfile->playerId,
                    kind: DuplicatePreventionKind::WarningShown,
                    timeId: $timeId->toString(),
                    puzzleId: $data->puzzle,
                    via: $stopwatchId !== null ? SolvingTimeSource::Stopwatch : SolvingTimeSource::Form,
                ));
            } elseif ($check->firstTryBlocks($firstTryResolution, $duplicateConfirmed)) {
                $addTimeForm->addError(new FormError($this->translator->trans('first_try.form_error')));
            }
        }

        $firstTry = $check->firstTry;

        if ($addTimeForm->isSubmitted() && $addTimeForm->isValid()) {
            $userId = $user->getUserIdentifier();
            $mode = $data->mode;
            $enteredPuzzle = $data->puzzle;

            if ($mode === PuzzleAddMode::Relax && $request->request->has('no_remember_date')) {
                $data->finishedAt = null;
            }

            try {
                // Step 1: Handle new puzzle creation (all modes)
                if (
                    is_string($data->puzzle)
                    && $data->puzzlePiecesCount !== null
                    && Uuid::isValid($data->puzzle) === false
                ) {
                    // Photo fallback only for Speed/Relax (not Collection)
                    if ($mode !== PuzzleAddMode::Collection && $data->puzzlePhoto === null && $data->finishedPuzzlesPhoto !== null) {
                        $data->puzzlePhoto = clone $data->finishedPuzzlesPhoto;
                    }

                    // The form refuses a new puzzle without a photo (PuzzleAddFormType::applyDynamicRules)
                    if ($data->puzzlePhoto === null) {
                        throw new \LogicException('A new puzzle reached the handler without a photo.');
                    }

                    // Sent again, the puzzle is already there and the handler creates nothing
                    $this->messageBus->dispatch(
                        new AddPuzzle(
                            puzzleId: $newPuzzleId,
                            userId: $userId,
                            puzzleName: $data->puzzle,
                            brand: $data->brand ?? '',
                            piecesCount: $data->puzzlePiecesCount,
                            puzzlePhoto: $data->puzzlePhoto,
                            eans: EanList::fromInputs($data->puzzleEans),
                            brandCodes: BrandCodeList::fromInputs($data->puzzleBrandCodes),
                            alternativeNames: $data->toPuzzleNames(),
                        ),
                    );

                    // After adding puzzle, change the data to the puzzle id for further handlers
                    $data->puzzle = $newPuzzleId->toString();

                    $this->addFlash('warning', $this->translator->trans('flashes.puzzle_needs_approve'));
                }

                // Step 2: Mode-specific handling
                $response = match ($mode) {
                    PuzzleAddMode::SpeedPuzzling => $this->handleSpeedPuzzling(
                        $data,
                        $timeId,
                        $userId,
                        $groupPlayers,
                        $stopwatchId,
                        $teamName,
                        $firstTryResolution,
                        // Only an answer to a same-day twin the check found counts as "saved anyway"
                        $duplicateConfirmed && $check->duplicates?->needsConfirmation() === true,
                    ),
                    PuzzleAddMode::Relax => $this->handleRelax($data, $timeId, $userId, $groupPlayers, $teamName),
                    PuzzleAddMode::Collection => $this->handleCollection($data, $userProfile->playerId),
                };

                $this->formPhotoStash->forget($restoredPhotos, $userProfile->playerId);

                return $response;
            } catch (HandlerFailedException $exception) {
                $refusal = $exception->getPrevious();

                if ($refusal instanceof SolvingTimeAlreadySaved) {
                    $this->formPhotoStash->forget($restoredPhotos, $userProfile->playerId);

                    return $this->answerResend($refusal, $userProfile->playerId, $mode, $stopwatchId);
                }

                // The ids are user input - with fresh ones the next submit goes through
                if ($refusal instanceof SolvingTimeIdTaken || $refusal instanceof SolvingTimeIdReused || $refusal instanceof PuzzleIdTaken) {
                    $timeId = Uuid::uuid7();
                    $newPuzzleId = Uuid::uuid7();
                }

                // A new puzzle saved before the result was refused: the form shows what was typed (the new-puzzle
                // fields stay open) and the corrected resubmit corrects that puzzle (AddPuzzleHandler)
                $data->puzzle = $enteredPuzzle;

                $firstTry = $this->handleException($exception, $addTimeForm) ?? $firstTry;
            }
        }

        return $this->render('puzzle_add.html.twig', [
            'active_stopwatch' => $activeStopwatch,
            'active_puzzle' => $activePuzzle,
            'solving_time_form' => $addTimeForm,
            'filled_group_players' => $groupPlayers,
            // Only the old co-puzzler rows list favorites up front; the picker fetches its suggestions on demand
            'favorite_players' => $this->coPuzzlerPicker->isEnabled() ? [] : $this->getFavoritePlayers->forPlayerId($userProfile->playerId),
            'copuzzler_picker_enabled' => $this->coPuzzlerPicker->isEnabled(),
            'copuzzler_picker' => $this->coPuzzlerPicker->formState($groupPlayers),
            'filled_team_name' => $teamName,
            'hide_new_puzzle' => $data->puzzle === null || trim($data->puzzle) === '' || Uuid::isValid($data->puzzle) || $data->brand === null,
            'collections' => $collections,
            'initial_mode' => $initialMode,
            'has_active_membership' => $hasActiveMembership,
            'system_collection_id' => Collection::SYSTEM_ID,
            'first_try' => $firstTry,
            'first_try_resolution' => $firstTryResolution->value,
            'duplicates' => $check->duplicates,
            'duplicate_confirmed' => $duplicateConfirmed,
            'kept_photos' => $this->formPhotoStash->keep($addTimeForm, $restoredPhotos, $userProfile->playerId),
            'secret_puzzle_notice' => $secretPuzzleNotice,
            'official_entry' => $officialEntry,
            'time_id' => $timeId->toString(),
            'new_puzzle_id' => $newPuzzleId->toString(),
        ]);
    }

    /**
     * @see PuzzleSolvingTime::isSameEntryAs()
     */
    private function isSameEntry(PuzzleSolvingTime $saved, PuzzleAddFormData $data, UuidInterface $newPuzzleId, Request $request): bool
    {
        $puzzleId = is_string($data->puzzle) && Uuid::isValid($data->puzzle) ? $data->puzzle : $newPuzzleId->toString();

        if ($data->mode === PuzzleAddMode::Relax) {
            $finishedAt = $request->request->has('no_remember_date') ? null : $data->finishedAt;

            return $saved->isSameEntryAs($puzzleId, null, $finishedAt, $this->clock->now());
        }

        return $saved->isSameEntryAs(
            $puzzleId,
            SolvingTime::fromHoursMinutesSeconds($data->timeHours, $data->timeMinutes, $data->timeSeconds)->seconds,
            $this->mistypedYearNormalizer->normalizeFinishedAt($data->finishedAt),
            $this->clock->now(),
        );
    }

    private function submittedIdOrNew(Request $request, string $field): UuidInterface
    {
        $submittedId = $request->request->getString($field);

        return Uuid::isValid($submittedId) ? Uuid::fromString($submittedId) : Uuid::uuid7();
    }

    /**
     * The form was sent again after its result had been saved (the answer got lost, the button was tapped twice):
     * nothing new was created, the player lands where the first save would have taken them.
     */
    private function answerResend(SolvingTimeAlreadySaved $resend, string $playerId, PuzzleAddMode $mode, null|string $stopwatchId): Response
    {
        // A dispatch of its own - the refused add was rolled back with everything written in it
        $this->messageBus->dispatch(new RecordDuplicatePrevention(
            playerId: $playerId,
            kind: DuplicatePreventionKind::ResendCaught,
            timeId: $resend->timeId,
            puzzleId: $resend->puzzleId,
            via: $stopwatchId !== null ? SolvingTimeSource::Stopwatch : SolvingTimeSource::Form,
        ));

        $this->addFlash('info', $this->translator->trans('flashes.result_already_saved'));

        if ($mode === PuzzleAddMode::Relax) {
            return $this->redirectToRoute('added_tracking_recap', ['trackingId' => $resend->timeId]);
        }

        return $this->redirectToRoute('added_time_recap', ['timeId' => $resend->timeId]);
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function handleSpeedPuzzling(
        PuzzleAddFormData $data,
        UuidInterface $timeId,
        string $userId,
        array $groupPlayers,
        null|string $stopwatchId,
        string $teamName,
        FirstTryResolution $firstTryResolution,
        bool $duplicateConfirmed,
    ): Response {
        assert($data->puzzle !== null);

        $timeString = $data->getTimeAsString();
        assert($timeString !== null);

        $this->messageBus->dispatch(
            new AddPuzzleSolvingTime(
                timeId: $timeId,
                userId: $userId,
                puzzleId: $data->puzzle,
                competitionId: $data->competition,
                time: $timeString,
                comment: $data->comment,
                finishedPuzzlesPhoto: $data->finishedPuzzlesPhoto,
                groupPlayers: $groupPlayers,
                finishedAt: $data->finishedAt,
                firstAttempt: $data->firstAttempt,
                unboxed: $data->unboxed,
                teamName: $teamName,
                firstTryResolution: $firstTryResolution,
                createdVia: $stopwatchId !== null ? SolvingTimeSource::Stopwatch : SolvingTimeSource::Form,
                // Finished by the same handler, in the same transaction as the result
                stopwatchId: $stopwatchId,
                duplicateConfirmed: $duplicateConfirmed,
            ),
        );

        return $this->redirectToRoute('added_time_recap', ['timeId' => $timeId]);
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function handleRelax(
        PuzzleAddFormData $data,
        UuidInterface $trackingId,
        string $userId,
        array $groupPlayers,
        string $teamName,
    ): Response {
        assert($data->puzzle !== null);

        $this->messageBus->dispatch(
            new AddPuzzleTracking(
                trackingId: $trackingId,
                userId: $userId,
                puzzleId: $data->puzzle,
                comment: $data->comment,
                finishedPuzzlesPhoto: $data->finishedPuzzlesPhoto,
                groupPlayers: $groupPlayers,
                finishedAt: $data->finishedAt,
                teamName: $teamName,
                createdVia: SolvingTimeSource::Form,
            ),
        );

        return $this->redirectToRoute('added_tracking_recap', ['trackingId' => $trackingId]);
    }

    private function handleCollection(
        PuzzleAddFormData $data,
        string $playerId,
    ): Response {
        assert($data->puzzle !== null);
        assert($data->collection !== null);

        $collectionId = $data->collection;
        $targetCollectionId = $collectionId;

        // Handle system collection - convert to null for dispatch
        if ($collectionId === Collection::SYSTEM_ID) {
            $targetCollectionId = null;
        } elseif (Uuid::isValid($data->collection) === false) {
            // Handle new collection creation if needed
            $newCollectionId = Uuid::uuid7();

            try {
                $this->messageBus->dispatch(
                    new CreateCollection(
                        collectionId: $newCollectionId->toString(),
                        playerId: $playerId,
                        name: $data->collection,
                        description: $data->collectionDescription,
                        visibility: $data->collectionVisibility,
                    ),
                );

                $targetCollectionId = $newCollectionId->toString();
            } catch (HandlerFailedException $exception) {
                // If collection already exists, we can still proceed
                if ($exception->getPrevious() instanceof CollectionAlreadyExists) {
                    $targetCollectionId = $exception->getPrevious()->collectionId;
                } else {
                    throw $exception;
                }
            }
        }

        $this->messageBus->dispatch(
            new AddPuzzleToCollection(
                playerId: $playerId,
                puzzleId: $data->puzzle,
                collectionId: $targetCollectionId,
                comment: $data->collectionComment,
            ),
        );

        $this->addFlash('success', $this->translator->trans('flashes.puzzle_added_to_collection'));

        // Redirect to the specific collection
        if ($targetCollectionId === null) {
            return $this->redirectToRoute('system_collection_detail', ['playerId' => $playerId]);
        }

        return $this->redirectToRoute('collection_detail', ['collectionId' => $targetCollectionId]);
    }

    /**
     * @param FormInterface<PuzzleAddFormData> $form
     */
    private function handleException(HandlerFailedException $exception, FormInterface $form): null|FirstTryAssessment
    {
        $realException = $exception->getPrevious();

        if ($realException instanceof FirstTryAlreadyTaken) {
            // Somebody saved a first try in the meantime - the notice explains what the handler saw
            $form->addError(new FormError($this->translator->trans('first_try.form_error')));

            return $realException->assessment;
        }

        if ($realException instanceof CanNotAssembleEmptyGroup) {
            $form->addError(new FormError($this->translator->trans('forms.empty_group_error')));
        } elseif ($realException instanceof SuspiciousPpm) {
            $form->addError(new FormError($this->translator->trans('forms.too_high_ppm')));
        } else {
            // No plain "try again" - the player first checks whether the result is there already
            $form->addError(new FormError($this->translator->trans('forms.could_not_save')));

            $this->logger->warning('Puzzle time could not be added', [
                'exception' => $exception,
            ]);
        }

        return null;
    }
}
