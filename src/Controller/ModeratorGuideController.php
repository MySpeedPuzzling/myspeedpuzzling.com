<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\FormData\AcceptModeratorInvitationFormData;
use SpeedPuzzling\Web\FormType\AcceptModeratorInvitationFormType;
use SpeedPuzzling\Web\Message\AcceptModeratorInvitation;
use SpeedPuzzling\Web\Query\GetModeratorInvitations;
use SpeedPuzzling\Web\Services\Moderators\ModeratorGuide;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The moderator guide (docs/features/community-moderators.md): how moderation works, what we ask of moderators and
 * where everything is. Invited players read it and agree to its pledge at the end - that is what makes them a
 * moderator; moderators and admins come back to it any time (key menu). English only, like the review pages.
 * Nobody else may open it.
 */
final class ModeratorGuideController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetModeratorInvitations $getModeratorInvitations,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    #[Route(path: '/en/moderator-guide', name: 'moderator_guide', defaults: ['_locale' => 'en'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirectToRoute('my_profile');
        }

        $invited = $profile->isModerator === false && $this->getModeratorInvitations->hasPending($profile->playerId);

        if ($invited === false && $profile->isModerator === false && $profile->isAdmin === false) {
            throw $this->createAccessDeniedException();
        }

        $form = null;

        if ($invited) {
            $data = new AcceptModeratorInvitationFormData();
            $form = $this->createForm(AcceptModeratorInvitationFormType::class, $data);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $this->messageBus->dispatch(new AcceptModeratorInvitation($profile->playerId));

                $this->addFlash('success', 'Welcome to the moderator team! The key icon in the top bar leads to everything in this guide.');

                return $this->redirectToRoute('moderator_guide', ['_fragment' => 'tools']);
            }
        } elseif ($request->isMethod('POST')) {
            // A pledge posted after the invitation was answered or withdrawn (another tab, a resend)
            return $this->redirectToRoute('moderator_guide');
        }

        return $this->render('moderators/guide.html.twig', [
            'invited' => $invited,
            'form' => $form,
            'motto' => ModeratorGuide::MOTTO,
        ]);
    }
}
