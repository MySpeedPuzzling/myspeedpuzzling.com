<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\FormData\EditProfileFormData;
use SpeedPuzzling\Web\FormType\EditProfileFormType;
use SpeedPuzzling\Web\Message\EditProfile;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EditProfileController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/upravit-profil',
            'en' => '/en/edit-profile',
        ],
        name: 'edit_profile',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $defaultData = EditProfileFormData::fromPlayerProfile($player);

        $editProfileForm = $this->createForm(EditProfileFormType::class, $defaultData);
        $editProfileForm->handleRequest($request);

        if ($editProfileForm->isSubmitted() && $editProfileForm->isValid()) {
            $data = $editProfileForm->getData();
            assert($data instanceof EditProfileFormData);

            $this->messageBus->dispatch(
                EditProfile::fromFormData($player->playerId, $data),
            );

            $this->addFlash('success', $this->translator->trans('flashes.profile_saved'));

            return $this->redirectToRoute('my_profile');
        }

        return $this->render('edit-profile.html.twig', [
            'player' => $player,
            'edit_profile_form' => $editProfileForm,
        ]);
    }
}
