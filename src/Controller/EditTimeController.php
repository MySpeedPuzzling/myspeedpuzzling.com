<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\FormData\PuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\FormType\PuzzleSolvingTimeFormType;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzlesOverview;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class EditTimeController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private PuzzlingTimeFormatter $timeFormatter,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPuzzlesOverview $getPuzzlesOverview,
        readonly private GetPuzzleOverview $getPuzzleOverview,
    ) {
    }

    #[Route(path: '/upravit-cas/{timeId}', name: 'edit_time', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $timeId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $solvedPuzzle = $this->getPlayerSolvedPuzzles->byTimeId($timeId);

        if ($solvedPuzzle->playerId !== $player->playerId) {
            throw $this->createAccessDeniedException();
        }



        $defaultData = new PuzzleSolvingTimeFormData();
        $defaultData->time = $this->timeFormatter->formatTime($solvedPuzzle->time);
        $defaultData->comment = $solvedPuzzle->comment;
        $defaultData->finishedAt = $solvedPuzzle->finishedAt;
        $defaultData->puzzleId = $solvedPuzzle->puzzleId;

        $groupPlayers = [];
        foreach ($solvedPuzzle->players ?? [] as $groupPlayer) {
            $groupPlayers[] = $groupPlayer->playerCode ?? $groupPlayer->playerName ?? '';
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

        $editTimeForm = $this->createForm(PuzzleSolvingTimeFormType::class, $defaultData);
        $editTimeForm->handleRequest($request);

        if ($isGroupPuzzlersValid === true && $editTimeForm->isSubmitted() && $editTimeForm->isValid()) {
            $data = $editTimeForm->getData();
            assert($data instanceof PuzzleSolvingTimeFormData);

            $this->messageBus->dispatch(
                EditPuzzleSolvingTime::fromFormData($user->getUserIdentifier(), $timeId, $groupPlayers, $data),
            );

            $this->addFlash('success','Upravené údaje jsme uložili.');

            return $this->redirectToRoute('my_profile');
        }

        /** @var array<string, array<PuzzleOverview>> $puzzlesPerManufacturer */
        $puzzlesPerManufacturer = [];
        foreach($this->getPuzzlesOverview->allApprovedOrAddedByPlayer($player->playerId) as $puzzle) {
            $puzzlesPerManufacturer[$puzzle->manufacturerName][] = $puzzle;
        }

        return $this->render('edit-time.html.twig', [
            'active_puzzle' => $this->getPuzzleOverview->byId($solvedPuzzle->puzzleId),
            'solved_puzzle' => $solvedPuzzle,
            'solving_time_form' => $editTimeForm,
            'filled_group_players' => $groupPlayers,
            'selected_add_puzzle' => false,
            'selected_add_manufacturer' => false,
            'puzzles' => $puzzlesPerManufacturer,
            'active_stopwatch' => null,
        ]);
    }
}
