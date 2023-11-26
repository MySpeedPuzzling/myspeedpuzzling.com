<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\AddPuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\FormData\SaveStopwatchFormData;
use SpeedPuzzling\Web\FormType\SaveStopwatchFormType;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\FinishStopwatch;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPuzzlesOverview;
use SpeedPuzzling\Web\Query\GetStopwatch;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class SaveStopwatchController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetStopwatch $getStopwatch,
        readonly private LoggerInterface $logger,
        readonly private MessageBusInterface $messageBus,
        readonly private GetPuzzlesOverview $getPuzzlesOverview,
        readonly private PuzzlingTimeFormatter $puzzlingTimeFormatter,
    ) {
    }

    #[Route(path: '/ulozit-stopky/{stopwatchId}', name: 'save_stopwatch', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] UserInterface $user, string $stopwatchId): Response
    {
        $userId = $user->getUserIdentifier();

        try {
            $player = $this->getPlayerProfile->byUserId($userId);
        } catch (PlayerNotFound $e) {
            $this->logger->critical('Stopwatch - could not get player', [
                'user_id' => $userId,
                'exception' => $e,
            ]);

            return $this->redirectToRoute('my_profile');
        }

        $activeStopwatch = $this->getStopwatch->byId($stopwatchId);

        $addTimeForm = $this->createForm(SaveStopwatchFormType::class);
        $addTimeForm->handleRequest($request);

        if ($addTimeForm->isSubmitted() && $addTimeForm->isValid()) {
            $data = $addTimeForm->getData();
            assert($data instanceof SaveStopwatchFormData);

            $userId = $user->getUserIdentifier();

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
                    ),
                );
            }

            if ($data->puzzleId !== null) {
                assert($data->playersCount !== null);

                $this->messageBus->dispatch(
                    new AddPuzzleSolvingTime(
                        userId: $userId,
                        puzzleId: $data->puzzleId,
                        time: $this->puzzlingTimeFormatter->formatTime($activeStopwatch->totalSeconds),
                        playersCount: $data->playersCount,
                        comment: $data->comment,
                        solvedPuzzlesPhoto: $data->solvedPuzzlesPhoto,
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

        /** @var array<string, array<PuzzleOverview>> $puzzlesPerManufacturer */
        $puzzlesPerManufacturer = [];
        foreach($this->getPuzzlesOverview->all() as $puzzle) {
            $puzzlesPerManufacturer[$puzzle->manufacturerName][] = $puzzle;
        }

        return $this->render('add-time.html.twig', [
            'active_stopwatch' => $activeStopwatch,
            'active_puzzle' => null,
            'puzzles' => $puzzlesPerManufacturer,
            'add_puzzle_solving_time_form' => $addTimeForm,
        ]);
    }
}
