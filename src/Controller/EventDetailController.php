<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetUserSolvedPuzzles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class EventDetailController extends AbstractController
{
    public function __construct(
        readonly private GetCompetitionEvents $getCompetitionEvents,
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetUserSolvedPuzzles $getUserSolvedPuzzles,
        readonly private GetCompetitionParticipants $getCompetitionParticipants,
        readonly private GetCompetitionRounds $getCompetitionRounds,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/eventy/{slug}',
            'en' => '/en/events/{slug}',
        ],
        name: 'event_detail',
    )]
    public function __invoke(Competition $competition, #[CurrentUser] UserInterface|null $user): Response
    {
        $competitionEvent = $this->getCompetitionEvents->byId($competition->id->toString());
        $puzzles = [];

        if ($competitionEvent->tagId !== null) {
            $puzzles = $this->getPuzzleOverview->byTagId($competitionEvent->tagId);
        }

        $userSolvedPuzzles = $this->getUserSolvedPuzzles->byUserId(
            $user?->getUserIdentifier()
        );

        $connectedParticipants = $this->getCompetitionParticipants->getConnectedParticipants($competition->id->toString());
        $notConnectedParticipants = $this->getCompetitionParticipants->getNotConnectedParticipants($competition->id->toString());
        $competitionRounds = $this->getCompetitionRounds->ofCompetition($competition->id->toString());
        $participantsRounds = $this->getCompetitionRounds->forAllCompetitionParticipants($competition->id->toString());

        return $this->render('event_detail.html.twig', [
            'event' => $competitionEvent,
            'puzzles' => $puzzles,
            'puzzles_solved_by_user' => $userSolvedPuzzles,
            'connected_participants' => $connectedParticipants,
            'not_connected_participants' => $notConnectedParticipants,
            'competition_rounds' => $competitionRounds,
            'participants_rounds' => $participantsRounds,
        ]);
    }
}
