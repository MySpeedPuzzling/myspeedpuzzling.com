<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CanNotAssembleEmptyGroup;
use SpeedPuzzling\Web\FormData\PuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\FormType\PuzzleSolvingTimeFormType;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\FinishStopwatch;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzlesOverview;
use SpeedPuzzling\Web\Query\GetStopwatch;
use SpeedPuzzling\Web\Results\PuzzleOverview;
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
        readonly private GetPuzzlesOverview $getPuzzlesOverview,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetStopwatch $getStopwatch,
        readonly private PuzzlingTimeFormatter $timeFormatter,
        readonly private TranslatorInterface $translator,
        readonly private GetFavoritePlayers $getFavoritePlayers,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pridat-cas/{puzzleId}',
            'en' => '/en/add-time/{puzzleId}',
        ],
        name: 'add_time',
        methods: ['GET', 'POST'],
    )]
    #[Route(
        path: [
            'cs' => '/ulozit-stopky/{stopwatchId}',
            'en' => '/en/save-stopwatch/{stopwatchId}',
        ],
        name: 'finish_stopwatch',
        methods: ['GET', 'POST'],
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

        /** @var array<string> $groupPlayers */
        $groupPlayers = $request->request->all('group_players');

        $isGroupPuzzlersValid = true;
        foreach ($groupPlayers as $groupPlayer) {
            if (trim($groupPlayer) === '') {
                $isGroupPuzzlersValid = false;
                break;
            }
        }

        $addTimeForm = $this->createForm(PuzzleSolvingTimeFormType::class, $data);
        $addTimeForm->handleRequest($request);
        $data = $addTimeForm->getData();
        assert($data instanceof PuzzleSolvingTimeFormData);

        if ($isGroupPuzzlersValid === true && $addTimeForm->isSubmitted() && $addTimeForm->isValid()) {
            if ($activePuzzle !== null) {
                $data->puzzleId = $activePuzzle->puzzleId;
                $data->addPuzzle = false;
            }

            $userId = $user->getUserIdentifier();

            // Adding new puzzles by user
            if (
                $data->addPuzzle === true
                && $data->puzzleName !== null
                && $data->puzzlePiecesCount !== null
                && (
                    $data->puzzleManufacturerId !== ''
                    || $data->puzzleManufacturerName !== null
                )
            ) {
                $newPuzzleId = Uuid::uuid7();
                $data->puzzleId = (string) $newPuzzleId;

                if ($data->puzzlePhoto === null && $data->finishedPuzzlesPhoto !== null) {
                    $data->puzzlePhoto = clone $data->finishedPuzzlesPhoto;
                }

                if ($data->addManufacturer === true && $data->puzzleManufacturerName !== null) {
                    $data->puzzleManufacturerId = null;
                }

                $this->messageBus->dispatch(
                    AddPuzzle::fromFormData($newPuzzleId, $userId, $data),
                );

                $this->addFlash('warning', $this->translator->trans('flashes.puzzle_needs_approve'));
            }

            if ($data->puzzleId !== null) {
                try {
                    $this->messageBus->dispatch(
                        AddPuzzleSolvingTime::fromFormData($userId, $groupPlayers, $data),
                    );

                    if ($activeStopwatch !== null) {
                        $this->messageBus->dispatch(
                            new FinishStopwatch(
                                $stopwatchId,
                                $userId,
                                $data->puzzleId,
                            ),
                        );
                    }

                    $this->addFlash('success', $this->translator->trans('flashes.time_added'));

                    return $this->redirectToRoute('my_profile');
                } catch (HandlerFailedException $exception) {
                    $realException = $exception->getPrevious();

                    if ($realException instanceof CanNotAssembleEmptyGroup) {
                        $addTimeForm->addError(new FormError($this->translator->trans('forms.empty_group_error')));
                    }
                }
            }
        }

        $userProfile = $this->retrieveLoggedUserProfile->getProfile();
        assert($userProfile !== null);

        /** @var array<string, array<PuzzleOverview>> $puzzlesPerManufacturer */
        $puzzlesPerManufacturer = [];
        foreach($this->getPuzzlesOverview->allApprovedOrAddedByPlayer($userProfile->playerId) as $puzzle) {
            $puzzlesPerManufacturer[$puzzle->manufacturerName][] = $puzzle;
        }

        if ($data->puzzleId !== null) {
            $activePuzzle = $this->getPuzzleOverview->byId($data->puzzleId);
        }

        return $this->render('add-time.html.twig', [
            'active_stopwatch' => $activeStopwatch,
            'active_puzzle' => $activePuzzle,
            'puzzles' => $puzzlesPerManufacturer,
            'solving_time_form' => $addTimeForm,
            'filled_group_players' => $groupPlayers,
            'selected_add_puzzle' => $data->addPuzzle,
            'selected_add_manufacturer' => $data->addManufacturer,
            'favorite_players' => $this->getFavoritePlayers->forPlayerId($userProfile->playerId),
        ]);
    }
}
