<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Query\GetModerationActions;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ModerationHistoryController extends AbstractController
{
    public function __construct(
        private readonly GetModerationActions $getModerationActions,
        private readonly GetPlayerProfile $getPlayerProfile,
    ) {
    }

    #[Route(
        path: '/admin/moderation/history/{playerId}',
        name: 'admin_moderation_history',
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(string $playerId): Response
    {
        $playerProfile = $this->getPlayerProfile->byId($playerId);
        $actions = $this->getModerationActions->forPlayer($playerId);
        $activeMute = $this->getModerationActions->activeMute($playerId);

        return $this->render('admin/moderation/user_history.html.twig', [
            'player' => $playerProfile,
            'actions' => $actions,
            'active_mute' => $activeMute,
        ]);
    }
}
