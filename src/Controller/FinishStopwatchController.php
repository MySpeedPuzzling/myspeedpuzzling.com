<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\SaveStopwatchFormData;
use SpeedPuzzling\Web\FormType\SaveStopwatchFormType;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\FinishStopwatch;
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
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class FinishStopwatchController extends AbstractController
{
    public function __construct(
        readonly private GetStopwatch $getStopwatch,
        readonly private MessageBusInterface $messageBus,
        readonly private GetPuzzlesOverview $getPuzzlesOverview,
        readonly private PuzzlingTimeFormatter $puzzlingTimeFormatter,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(path: '/ulozit-stopky/{stopwatchId}', name: 'finish_stopwatch', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] UserInterface $user, string $stopwatchId): Response
    {
        $activeStopwatch = $this->getStopwatch->byId($stopwatchId);
        $activePuzzle = null;
        
        if ($activeStopwatch->status === StopwatchStatus::Finished) {
            $this->addFlash('warning','Tyto stopky byly již uloženy.');

            return $this->redirectToRoute('my_profile');
        }

        if ($activeStopwatch->status !== StopwatchStatus::Paused) {
            $this->addFlash('warning','Prosím zastavte si stopky, pouze zastavené stoupky lze uložit.');

            return $this->redirectToRoute('stopwatch', [
                'stopwatchId' => $stopwatchId,
            ]);
        }

        $addTimeForm = $this->createForm(SaveStopwatchFormType::class, new SaveStopwatchFormData());
        $addTimeForm->handleRequest($request);

        if ($addTimeForm->isSubmitted() && $addTimeForm->isValid()) {
            $data = $addTimeForm->getData();
            assert($data instanceof SaveStopwatchFormData);

            $userId = $user->getUserIdentifier();

            if ($activeStopwatch->puzzleId !== null) {
                $data->puzzleId = $activeStopwatch->puzzleId;
            }

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

                $this->messageBus->dispatch(
                    new AddPuzzle(
                        puzzleId: $newPuzzleId,
                        userId: $userId,
                        puzzleName: $data->puzzleName,
                        piecesCount: $data->puzzlePiecesCount,
                        manufacturerId: $data->puzzleManufacturerId,
                        manufacturerName: $data->puzzleManufacturerName,
                        puzzlePhoto: $data->puzzlePhoto,
                        puzzleEan: $data->puzzleEan,
                        puzzleIdentificationNumber: $data->puzzleIdentificationNumber,
                    ),
                );
            }

            if ($data->puzzleId !== null) {
                /** @var array<string> $groupPlayers */
                $groupPlayers = $request->request->all('group_players');

                $this->messageBus->dispatch(
                    new AddPuzzleSolvingTime(
                        userId: $userId,
                        puzzleId: $data->puzzleId,
                        time: $this->puzzlingTimeFormatter->formatTime($activeStopwatch->totalSeconds),
                        comment: $data->comment,
                        finishedPuzzlesPhoto: $data->finishedPuzzlesPhoto,
                        groupPlayers: $groupPlayers,
                        finishedAt: $data->finishedAt,
                    ),
                );

                $this->messageBus->dispatch(
                    new FinishStopwatch(
                        $stopwatchId,
                        $userId,
                        $data->puzzleId,
                    ),
                );

                $this->addFlash('success','Skvělá práce! Skládání jsme zaznamenali.');

                return $this->redirectToRoute('my_profile');
            }

            $addTimeForm->addError(new FormError('Pro přidání času vyberte puzzle ze seznamu nebo prosím vypište informace o puzzlích'));
        }

        $userProfile = $this->retrieveLoggedUserProfile->getProfile();

        /** @var array<string, array<PuzzleOverview>> $puzzlesPerManufacturer */
        $puzzlesPerManufacturer = [];
        foreach($this->getPuzzlesOverview->allApprovedOrAddedByPlayer($userProfile?->playerId) as $puzzle) {
            $puzzlesPerManufacturer[$puzzle->manufacturerName][] = $puzzle;
        }

        if ($activeStopwatch->puzzleId !== null) {
            $activePuzzle = $this->getPuzzleOverview->byId($activeStopwatch->puzzleId);
        }

        return $this->render('add-time.html.twig', [
            'active_stopwatch' => $activeStopwatch,
            'active_puzzle' => $activePuzzle,
            'puzzles' => $puzzlesPerManufacturer,
            'add_puzzle_solving_time_form' => $addTimeForm,
        ]);
    }
}
