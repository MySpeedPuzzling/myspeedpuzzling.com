<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\NonUniquePlayerCode;
use SpeedPuzzling\Web\FormData\EditProfileFormData;
use SpeedPuzzling\Web\FormData\PlayerCodeFormData;
use SpeedPuzzling\Web\FormData\PlayerVisibilityFormData;
use SpeedPuzzling\Web\FormType\EditProfileFormType;
use SpeedPuzzling\Web\FormType\PlayerCodeFormType;
use SpeedPuzzling\Web\FormType\PlayerVisibilityFormType;
use SpeedPuzzling\Web\Message\EditPlayerCode;
use SpeedPuzzling\Web\Message\EditPlayerVisibility;
use SpeedPuzzling\Web\Message\EditProfile;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
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
        readonly private LoggerInterface $logger,
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

            $this->messageBus->dispatch(
                EditProfile::fromFormData($player->playerId, $data),
            );

            $this->addFlash('success', $this->translator->trans('flashes.profile_saved'));

            return $this->redirectToRoute('my_profile');
        }

        $editCodeFormData = PlayerCodeFormData::fromPlayerProfile($player);

        $editCodeForm = $this->createForm(PlayerCodeFormType::class, $editCodeFormData);
        $editCodeForm->handleRequest($request);

        if ($editCodeForm->isSubmitted() && $editCodeForm->isValid()) {
            if ($player->activeMembership !== true) {
                $this->addFlash('warning', $this->translator->trans('flashes.exclusive_membership_feature'));

                return $this->redirectToRoute('membership');
            }

            try {
                $this->messageBus->dispatch(
                    new EditPlayerCode($player->playerId, $editCodeFormData->code)
                );

                $this->addFlash('success', $this->translator->trans('flashes.profile_saved'));
            } catch (HandlerFailedException $exception) {
                $realException = $exception->getPrevious();

                if ($realException instanceof NonUniquePlayerCode) {
                    $this->addFlash('danger', $this->translator->trans('flashes.non_unique_player_code'));
                } else {
                    $this->logger->error('Changing player code failed', [
                        'exception' => $exception,
                    ]);

                    $this->addFlash('danger', $this->translator->trans('flashes.unknown_error'));
                }

                return $this->redirectToRoute('edit_profile');
            }

            return $this->redirectToRoute('my_profile');
        }

        $editVisibilityFormData = PlayerVisibilityFormData::fromPlayerProfile($player);

        $editVisibilityForm = $this->createForm(PlayerVisibilityFormType::class, $editVisibilityFormData);
        $editVisibilityForm->handleRequest($request);

        if ($editVisibilityForm->isSubmitted() && $editVisibilityForm->isValid()) {
            if ($player->activeMembership !== true) {
                $this->addFlash('warning', $this->translator->trans('flashes.exclusive_membership_feature'));

                return $this->redirectToRoute('membership');
            }

            $this->messageBus->dispatch(
                new EditPlayerVisibility($player->playerId, $editVisibilityFormData->isPrivate)
            );

            $this->addFlash('success', $this->translator->trans('flashes.profile_saved'));

            return $this->redirectToRoute('my_profile');
        }

        return $this->render('edit-profile.html.twig', [
            'player' => $player,
            'edit_profile_form' => $editProfileForm,
            'edit_code_form' => $editCodeForm,
            'edit_visibility_form' => $editVisibilityForm,
        ]);
    }
}
