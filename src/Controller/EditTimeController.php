<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\FormData\PuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\FormType\PuzzleSolvingTimeFormType;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EditTimeController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private PuzzlingTimeFormatter $timeFormatter,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPuzzlesOverview $getPuzzlesOverview,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private TranslatorInterface $translator,
        readonly private GetFavoritePlayers $getFavoritePlayers,
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

        $data = new PuzzleSolvingTimeFormData();
        $data->time = $this->timeFormatter->formatTime($solvedPuzzle->time);
        $data->comment = $solvedPuzzle->comment;
        $data->finishedAt = $solvedPuzzle->finishedAt;
        $data->puzzle = $solvedPuzzle->puzzleId;
        $data->brand = $solvedPuzzle->manufacturerId;
        $data->firstAttempt = $solvedPuzzle->firstAttempt;
        $data->competition = $solvedPuzzle->competitionId;

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

        $editTimeForm = $this->createForm(PuzzleSolvingTimeFormType::class, $data);
        $editTimeForm->handleRequest($request);

        if ($isGroupPuzzlersValid === true && $editTimeForm->isSubmitted() && $editTimeForm->isValid()) {
            $this->messageBus->dispatch(
                EditPuzzleSolvingTime::fromFormData($user->getUserIdentifier(), $timeId, $groupPlayers, $data),
            );

            $this->addFlash('success', $this->translator->trans('flashes.time_edited'));

            return $this->redirectToRoute('my_profile');
        }

        /** @var array<string, array<PuzzleOverview>> $puzzlesPerManufacturer */
        $puzzlesPerManufacturer = [];
        foreach ($this->getPuzzlesOverview->allApprovedOrAddedByPlayer($player->playerId) as $puzzle) {
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
            'favorite_players' => $this->getFavoritePlayers->forPlayerId($player->playerId),
        ]);
    }
}
