<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\CollectionAlreadyExists;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\FormData\PuzzleAddFormData;
use SpeedPuzzling\Web\FormType\PuzzleAddFormType;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Message\AddPuzzleTracking;
use SpeedPuzzling\Web\Message\CreateCollection;
use SpeedPuzzling\Web\Message\FinishStopwatch;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetStopwatch;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\PuzzleAddMode;
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

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AddTimeController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetStopwatch $getStopwatch,
        readonly private TranslatorInterface $translator,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private LoggerInterface $logger,
        readonly private GetPlayerCollections $getPlayerCollections,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pridat-cas/{puzzleId}',
            'en' => '/en/add-time/{puzzleId}',
            'es' => '/es/anadir-tiempo/{puzzleId}',
            'ja' => '/ja/時間追加/{puzzleId}',
            'fr' => '/fr/ajouter-temps/{puzzleId}',
            'de' => '/de/zeit-hinzufuegen/{puzzleId}',
        ],
        name: 'add_time',
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
        #[CurrentUser] User $user,
        null|string $puzzleId = null,
        null|string $stopwatchId = null,
    ): Response {
        $userProfile = $this->retrieveLoggedUserProfile->getProfile();
        assert($userProfile !== null);

        $activePuzzle = null;
        $activeStopwatch = null;
        $data = new PuzzleAddFormData();

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

            if ($activeStopwatch->status === StopwatchStatus::Finished) {
                $this->addFlash('warning', $this->translator->trans('flashes.stopwatch_already_saved'));

                return $this->redirectToRoute('my_profile');
            }

            if ($activeStopwatch->puzzleId !== null) {
                $activePuzzle = $this->getPuzzleOverview->byId($activeStopwatch->puzzleId);
            }
        }

        if ($activePuzzle !== null) {
            $data->brand = $activePuzzle->manufacturerId;
            $data->puzzle = $activePuzzle->puzzleId;
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

        // For non-members in collection mode, force system collection
        $hasActiveMembership = $userProfile->activeMembership;
        if ($data->mode === PuzzleAddMode::Collection && $hasActiveMembership === false) {
            $data->collection = Collection::SYSTEM_ID;
        }

        /** @var array<string> $groupPlayers */
        $groupPlayers = $request->request->all('group_players');

        $isGroupPuzzlersValid = true;
        foreach ($groupPlayers as $groupPlayer) {
            if (trim($groupPlayer) === '') {
                $isGroupPuzzlersValid = false;
                break;
            }
        }

        // Get player collections for form options (include system collection)
        $collections = [];
        $systemCollectionName = $this->translator->trans('collections.system_name');
        $collections[$systemCollectionName] = Collection::SYSTEM_ID;

        foreach ($this->getPlayerCollections->byPlayerId($userProfile->playerId) as $collection) {
            if ($collection->collectionId !== null) {
                $collections[$collection->name] = $collection->collectionId;
            }
        }

        $addTimeForm = $this->createForm(PuzzleAddFormType::class, $data, [
            'active_puzzle' => $activePuzzle,
            'collections' => $collections,
            'has_active_membership' => $hasActiveMembership,
        ]);
        $addTimeForm->handleRequest($request);

        if ($isGroupPuzzlersValid === true && $addTimeForm->isSubmitted() && $addTimeForm->isValid()) {
            $userId = $user->getUserIdentifier();
            $mode = $data->mode;

            // Step 1: Handle new puzzle creation (all modes)
            $newPuzzleCreated = false;
            if (
                is_string($data->puzzle)
                && $data->puzzlePiecesCount !== null
                && Uuid::isValid($data->puzzle) === false
            ) {
                $newPuzzleId = Uuid::uuid7();

                // Photo fallback only for Speed/Relax (not Collection)
                if ($mode !== PuzzleAddMode::Collection && $data->puzzlePhoto === null && $data->finishedPuzzlesPhoto !== null) {
                    $data->puzzlePhoto = clone $data->finishedPuzzlesPhoto;
                }

                $this->messageBus->dispatch(
                    new AddPuzzle(
                        puzzleId: $newPuzzleId,
                        userId: $userId,
                        puzzleName: $data->puzzle,
                        brand: $data->brand ?? '',
                        piecesCount: $data->puzzlePiecesCount,
                        puzzlePhoto: $data->puzzlePhoto,
                        puzzleEan: $data->puzzleEan,
                        puzzleIdentificationNumber: $data->puzzleIdentificationNumber,
                    ),
                );

                // After adding puzzle, change the data to the puzzle id for further handlers
                $data->puzzle = $newPuzzleId->toString();
                $newPuzzleCreated = true;

                $this->addFlash('warning', $this->translator->trans('flashes.puzzle_needs_approve'));
            }

            // Step 2: Mode-specific handling
            try {
                switch ($mode) {
                    case PuzzleAddMode::SpeedPuzzling:
                        return $this->handleSpeedPuzzling($data, $userId, $groupPlayers, $activeStopwatch, $stopwatchId);

                    case PuzzleAddMode::Relax:
                        return $this->handleRelax($data, $userId, $groupPlayers);

                    case PuzzleAddMode::Collection:
                        return $this->handleCollection($data, $userProfile->playerId);
                }
            } catch (HandlerFailedException $exception) {
                $this->handleException($exception, $addTimeForm);
            }
        }

        return $this->render('add-time.html.twig', [
            'active_stopwatch' => $activeStopwatch,
            'active_puzzle' => $activePuzzle,
            'solving_time_form' => $addTimeForm,
            'filled_group_players' => $groupPlayers,
            'favorite_players' => $this->getFavoritePlayers->forPlayerId($userProfile->playerId),
            'hide_new_puzzle' => Uuid::isValid($data->puzzle ?? '') || $data->brand === null,
            'collections' => $collections,
            'initial_mode' => $initialMode,
            'has_active_membership' => $hasActiveMembership,
            'system_collection_id' => Collection::SYSTEM_ID,
        ]);
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function handleSpeedPuzzling(
        PuzzleAddFormData $data,
        string $userId,
        array $groupPlayers,
        mixed $activeStopwatch,
        null|string $stopwatchId,
    ): Response {
        $timeId = Uuid::uuid7();

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
            ),
        );

        if ($activeStopwatch !== null && $stopwatchId !== null) {
            $this->messageBus->dispatch(
                new FinishStopwatch(
                    $stopwatchId,
                    $userId,
                    $data->puzzle,
                ),
            );
        }

        return $this->redirectToRoute('added_time_recap', ['timeId' => $timeId]);
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function handleRelax(
        PuzzleAddFormData $data,
        string $userId,
        array $groupPlayers,
    ): Response {
        $trackingId = Uuid::uuid7();

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
    private function handleException(HandlerFailedException $exception, FormInterface $form): void
    {
        $realException = $exception->getPrevious();

        if ($realException instanceof CanNotAssembleEmptyGroup) {
            $form->addError(new FormError($this->translator->trans('forms.empty_group_error')));
        } elseif ($realException instanceof SuspiciousPpm) {
            $form->addError(new FormError($this->translator->trans('forms.too_high_ppm')));
        } else {
            $form->addError(new FormError($this->translator->trans('forms.too_high_ppm')));

            $this->logger->warning('Puzzle time could not be added', [
                'exception' => $exception,
            ]);
        }
    }
}
