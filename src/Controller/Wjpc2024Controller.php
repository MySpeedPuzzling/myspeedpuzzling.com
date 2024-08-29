<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetWjpcParticipants;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class Wjpc2024Controller extends AbstractController
{
    public function __construct(
        readonly private GetWjpcParticipants $getWjpcParticipants,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/wjpc-2024',
            'en' => '/en/wjpc-2024',
        ],
        name: 'wjpc2024',
    )]
    public function __invoke(#[CurrentUser] UserInterface|null $user): Response
    {
        $connectedParticipants = $this->getWjpcParticipants->getConnectedParticipants();
        $notConnectedParticipants = $this->getWjpcParticipants->getNotConnectedParticipants();
        $connectedParticipantsByGroup = [];
        $notConnectedParticipantsByGroup = [];

        foreach ($connectedParticipants as $connectedParticipant) {
            $firstRound = $connectedParticipant->rounds[0] ?? null;

            if ($firstRound !== null) {
                $connectedParticipantsByGroup[$firstRound][] = $connectedParticipant;
            }
        }

        foreach ($notConnectedParticipants as $notConnectedParticipant) {
            $firstRound = $notConnectedParticipant->rounds[0] ?? null;

            if ($firstRound !== null) {
                $notConnectedParticipantsByGroup[$firstRound][] = $notConnectedParticipant;
            }
        }

        return $this->render('wjpc2024.html.twig', [
            'connected_participants' => $connectedParticipants,
            'connected_participants_by_group' => $connectedParticipantsByGroup,
            'not_connected_participants' => $notConnectedParticipants,
            'not_connected_participants_by_group' => $notConnectedParticipantsByGroup,
        ]);
    }
}
