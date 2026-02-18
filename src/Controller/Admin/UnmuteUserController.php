<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Message\UnmuteUser;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class UnmuteUserController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/admin/moderation/unmute/{playerId}',
        name: 'admin_unmute_user',
        methods: ['POST'],
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(string $playerId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            throw $this->createAccessDeniedException();
        }

        $this->messageBus->dispatch(new UnmuteUser(
            targetPlayerId: $playerId,
            adminId: $player->playerId,
        ));

        $this->addFlash('success', $this->translator->trans('moderation.user_unmuted'));

        return $this->redirectToRoute('admin_moderation_dashboard');
    }
}
