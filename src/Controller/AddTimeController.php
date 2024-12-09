<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\FormData\PuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\FormType\PuzzleSolvingTimeFormType;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\FinishStopwatch;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetStopwatch;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\StopwatchStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AddTimeController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetStopwatch $getStopwatch,
        readonly private PuzzlingTimeFormatter $timeFormatter,
        readonly private TranslatorInterface $translator,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pridat-cas/{puzzleId}',
            'en' => '/en/add-time/{puzzleId}',
        ],
        name: 'add_time',
    )]
    #[Route(
        path: [
            'cs' => '/ulozit-stopky/{stopwatchId}',
            'en' => '/en/save-stopwatch/{stopwatchId}',
        ],
        name: 'finish_stopwatch',
    )]
    public function __invoke(
        Request $request,
        #[CurrentUser] User $user,
        null|string $puzzleId = null,
        null|string $stopwatchId = null,
    ): Response {
        $activePuzzle = null;
        $activeStopwatch = null;
        $data = new PuzzleSolvingTimeFormData();

        if ($puzzleId !== null) {
            $activePuzzle = $this->getPuzzleOverview->byId($puzzleId);
        }

        if ($stopwatchId !== null) {
            $activeStopwatch = $this->getStopwatch->byId($stopwatchId);
            $data->time = $this->timeFormatter->formatTime($activeStopwatch->totalSeconds);

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

        /** @var array<string> $groupPlayers */
        $groupPlayers = $request->request->all('group_players');

        $isGroupPuzzlersValid = true;
        foreach ($groupPlayers as $groupPlayer) {
            if (trim($groupPlayer) === '') {
                $isGroupPuzzlersValid = false;
                break;
            }
        }

        $addTimeForm = $this->createForm(PuzzleSolvingTimeFormType::class, $data, [
            'active_puzzle' => $activePuzzle,
        ]);
        $addTimeForm->handleRequest($request);

        if ($isGroupPuzzlersValid === true && $addTimeForm->isSubmitted() && $addTimeForm->isValid()) {
            $timeId = Uuid::uuid7();
            $userId = $user->getUserIdentifier();

            // Adding new puzzles by user
            if (
                is_string($data->puzzle)
                && $data->puzzlePiecesCount !== null
                && Uuid::isValid($data->puzzle) === false
            ) {
                $newPuzzleId = Uuid::uuid7();

                if ($data->puzzlePhoto === null && $data->finishedPuzzlesPhoto !== null) {
                    $data->puzzlePhoto = clone $data->finishedPuzzlesPhoto;
                }

                $this->messageBus->dispatch(
                    AddPuzzle::fromFormData($newPuzzleId, $userId, $data),
                );

                // After adding puzzle, change the data to the puzzle id for further handlers
                $data->puzzle = $newPuzzleId->toString();

                $this->addFlash('warning', $this->translator->trans('flashes.puzzle_needs_approve'));
            }

            try {
                $this->messageBus->dispatch(
                    AddPuzzleSolvingTime::fromFormData($timeId, $userId, $groupPlayers, $data),
                );

                if ($activeStopwatch !== null) {
                    assert($data->puzzle !== null);

                    $this->messageBus->dispatch(
                        new FinishStopwatch(
                            $stopwatchId,
                            $userId,
                            $data->puzzle,
                        ),
                    );
                }

                return $this->redirectToRoute('added_time_recap', ['timeId' => $timeId]);
            } catch (HandlerFailedException $exception) {
                $realException = $exception->getPrevious();

                if ($realException instanceof CanNotAssembleEmptyGroup) {
                    $addTimeForm->addError(new FormError($this->translator->trans('forms.empty_group_error')));
                }

                if ($realException instanceof SuspiciousPpm) {
                    $addTimeForm->addError(new FormError($this->translator->trans('forms.too_high_ppm')));
                }

                $this->logger->warning('Puzzle time could not be added', [
                    'exception' => $exception,
                ]);
            }
        }

        $userProfile = $this->retrieveLoggedUserProfile->getProfile();
        assert($userProfile !== null);

        return $this->render('add-time.html.twig', [
            'active_stopwatch' => $activeStopwatch,
            'active_puzzle' => $activePuzzle,
            'solving_time_form' => $addTimeForm,
            'filled_group_players' => $groupPlayers,
            'favorite_players' => $this->getFavoritePlayers->forPlayerId($userProfile->playerId),
            'hide_new_puzzle' => Uuid::isValid($data->puzzle ?? '') || $data->brand === null,
        ]);
    }
}
