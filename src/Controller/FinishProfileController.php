<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\FormData\EditProfileFormData;
use SpeedPuzzling\Web\FormType\EditProfileFormType;
use SpeedPuzzling\Web\Message\EditProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The newcomer's short way to a profile (docs/features/getting-started-guide.md): the same
 * form and the same message as "Edit profile", on a page that shows name, photo and
 * country instead of every setting the account has.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class FinishProfileController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/dokoncit-profil',
            'en' => '/en/finish-profile',
            'es' => '/es/completar-perfil',
            'ja' => '/ja/プロフィール完成',
            'fr' => '/fr/completer-profil',
            'de' => '/de/profil-vervollstaendigen',
        ],
        name: 'finish_profile',
    )]
    public function __invoke(Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $form = $this->createForm(EditProfileFormType::class, EditProfileFormData::fromPlayerProfile($player));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->messageBus->dispatch(
                EditProfile::fromFormData($player->playerId, $form->getData()),
            );

            $this->addFlash('success', $this->translator->trans('flashes.profile_saved'));

            return $this->redirectToRoute('hub');
        }

        return $this->render('onboarding/finish_profile.html.twig', [
            'player' => $player,
            'form' => $form,
        ]);
    }
}
