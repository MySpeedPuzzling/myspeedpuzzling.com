<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PrivateProfile;

use SpeedPuzzling\Web\Exceptions\PrivateProfileViewersLimitReached;
use SpeedPuzzling\Web\FormData\AllowPrivateProfileViewersFormData;
use SpeedPuzzling\Web\FormType\AllowPrivateProfileViewersFormType;
use SpeedPuzzling\Web\Message\AllowPrivateProfileViewer;
use SpeedPuzzling\Web\MessageHandler\AllowPrivateProfileViewerHandler;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
use SpeedPuzzling\Web\Query\GetPrivateProfileViewers;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Who can see me": the allow list of the signed-in player's private profile
 * (docs/features/private-profile-allow-list.md).
 */
final class PrivateProfileViewersController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'private-profile-viewer';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPrivateProfileViewers $getPrivateProfileViewers,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/en/who-can-see-my-profile',
        name: 'private_profile_viewers',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $owner = $this->retrieveLoggedUserProfile->getProfile();
        assert($owner !== null);

        // The private profile itself is members-exclusive, and so is its allow list
        if ($owner->activeMembership === false) {
            return $this->redirectToRoute('edit_profile');
        }

        $data = new AllowPrivateProfileViewersFormData();
        $form = $this->createForm(AllowPrivateProfileViewersFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                foreach ($data->players as $playerId) {
                    $this->messageBus->dispatch(new AllowPrivateProfileViewer($owner->playerId, $playerId));
                }

                $this->addFlash('success', $this->translator->trans('private_profile_viewers.added'));
            } catch (HandlerFailedException $exception) {
                if (!$exception->getPrevious() instanceof PrivateProfileViewersLimitReached) {
                    throw $exception;
                }

                $this->addFlash('warning', $this->translator->trans('private_profile_viewers.limit_reached', [
                    '%limit%' => AllowPrivateProfileViewerHandler::MAX_VIEWERS,
                ]));
            }

            return $this->redirectToRoute('private_profile_viewers');
        }

        $viewers = $this->getPrivateProfileViewers->ofOwner($owner->playerId);
        $allowedIds = array_map(static fn (PlayerIdentification $viewer): string => $viewer->playerId, $viewers);

        return $this->render('private_profile/viewers.html.twig', [
            'owner' => $owner,
            'viewers' => $viewers,
            'suggestions' => array_values(array_filter(
                $this->getFavoritePlayers->forPlayerId($owner->playerId),
                static fn (PlayerIdentification $favorite): bool => in_array($favorite->playerId, $allowedIds, true) === false,
            )),
            'form' => $form,
        ]);
    }
}
