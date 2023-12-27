<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\FormData\EditPuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\FormType\EditPuzzleSolvingTimeFormType;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
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
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private PuzzlingTimeFormatter $timeFormatter,
    ) {
    }

    #[Route(path: '/upravit-cas/{timeId}', name: 'edit_time', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $timeId): Response
    {
        $solvedPuzzle = $this->getPlayerSolvedPuzzles->byTimeId($timeId);
        $userId = $user->getUserIdentifier();
        $player = $this->getPlayerProfile->byUserId($userId);

        if ($solvedPuzzle->addedByPlayerId !== $player->playerId) {
            throw $this->createAccessDeniedException();
        }

        $defaultData = new EditPuzzleSolvingTimeFormData();
        $defaultData->time = $this->timeFormatter->formatTime($solvedPuzzle->time);
        $defaultData->comment = $solvedPuzzle->comment;
        $defaultData->finishedAt = $solvedPuzzle->finishedAt;

        $editTimeForm = $this->createForm(EditPuzzleSolvingTimeFormType::class, $defaultData);
        $editTimeForm->handleRequest($request);

        if ($editTimeForm->isSubmitted() && $editTimeForm->isValid()) {
            $data = $editTimeForm->getData();
            assert($data instanceof EditPuzzleSolvingTimeFormData);

            /** @var array<string> $groupPlayers */
            $groupPlayers = $request->request->all('group_players');

            $this->messageBus->dispatch(
                EditPuzzleSolvingTime::fromFormData($userId, $timeId, $groupPlayers, $data),
            );

            $this->addFlash('success','Upravené údaje jsme uložili.');

            return $this->redirectToRoute('my_profile');
        }

        return $this->render('edit-time.html.twig', [
            'solved_puzzle' => $solvedPuzzle,
            'edit_puzzle_solving_time_form' => $editTimeForm,
        ]);
    }
}
