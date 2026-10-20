<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\FormData\AddModeratorsFormData;
use SpeedPuzzling\Web\FormType\AddModeratorsFormType;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\InviteModerator;
use SpeedPuzzling\Web\Query\GetModeratorInvitations;
use SpeedPuzzling\Web\Query\GetModerators;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admins only - a moderator must never be able to appoint another one. Adding a player sends an invitation: they
 * become a moderator only once they went through the moderator guide and agreed to it.
 */
#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class ModeratorsController extends AbstractController
{
    public function __construct(
        private readonly GetModerators $getModerators,
        private readonly GetModeratorInvitations $getModeratorInvitations,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/admin/moderators', name: 'admin_moderators', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $data = new AddModeratorsFormData();
        $form = $this->createForm(AddModeratorsFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $invitedBy = $this->retrieveLoggedUserProfile->getProfile()?->playerId;

            foreach ($data->players as $playerId) {
                $this->messageBus->dispatch(new InviteModerator(Uuid::uuid7(), $playerId, $invitedBy));
            }

            $this->addFlash('success', $this->translator->trans('admin.moderators.invited', ['%count%' => count($data->players)]));

            return $this->redirectToRoute('admin_moderators');
        }

        return $this->render('admin/moderators.html.twig', [
            'moderators' => $this->getModerators->all(),
            'invitations' => $this->getModeratorInvitations->notAccepted(),
            'form' => $form,
        ]);
    }
}
