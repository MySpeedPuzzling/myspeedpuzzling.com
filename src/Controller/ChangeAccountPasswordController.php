<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\CurrentPasswordDoesNotMatch;
use SpeedPuzzling\Web\FormData\ChangePasswordFormData;
use SpeedPuzzling\Web\FormType\ChangePasswordFormType;
use SpeedPuzzling\Web\Message\ChangeAccountPassword;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Native change-password (issue #147) - the successor to the #161 card, which
 * asked Auth0 to email a reset link. Reachable for any account that already
 * lives in user_account: native registrants during window A, everybody after the
 * Stage B import. Legacy Auth0 sessions keep the old card until then.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ChangeAccountPasswordController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/upravit-profil/zmenit-heslo',
            'en' => '/en/edit-profile/change-password',
            'es' => '/es/editar-perfil/cambiar-contrasena',
            'ja' => '/ja/プロフィール編集/パスワード変更',
            'fr' => '/fr/modifier-profil/changer-mot-de-passe',
            'de' => '/de/profil-bearbeiten/passwort-aendern',
        ],
        name: 'change_account_password',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, #[CurrentUser] UserInterface $user): Response
    {
        if (!$user instanceof UserAccount) {
            // Window A: a legacy Auth0 session has no local password to change
            return $this->redirectToRoute('edit_profile');
        }

        $data = new ChangePasswordFormData();
        $form = $this->createForm(ChangePasswordFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->messageBus->dispatch(
                    new ChangeAccountPassword(
                        userId: $user->getUserIdentifier(),
                        currentPassword: $data->currentPassword,
                        newPassword: $data->newPassword,
                    ),
                );
            } catch (HandlerFailedException $exception) {
                if ($exception->getPrevious() instanceof CurrentPasswordDoesNotMatch) {
                    $form->get('currentPassword')->addError(
                        new FormError($this->translator->trans('edit_profile.change_password_wrong_current')),
                    );

                    return $this->renderPage($form);
                }

                $this->logger->error('Changing the account password failed', [
                    'exception' => $exception,
                ]);

                $this->addFlash('danger', $this->translator->trans('flashes.password_change_failed'));

                return $this->renderPage($form);
            }

            $this->addFlash('success', $this->translator->trans('edit_profile.change_password_saved'));

            return $this->redirectToRoute('edit_profile');
        }

        return $this->renderPage($form);
    }

    /**
     * @param FormInterface<ChangePasswordFormData> $form
     */
    private function renderPage(FormInterface $form): Response
    {
        return $this->render('change_account_password.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
