<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\CurrentPasswordDoesNotMatch;
use SpeedPuzzling\Web\Exceptions\EmailAlreadyRegistered;
use SpeedPuzzling\Web\FormData\ChangeEmailFormData;
use SpeedPuzzling\Web\FormType\ChangeEmailFormType;
use SpeedPuzzling\Web\Message\ChangeAccountEmail;
use SpeedPuzzling\Web\Message\SendEmailVerificationLink;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Change the address the account signs in with (issue #147). The new address is
 * unverified until its link is clicked, and the confirmation goes to the new
 * inbox - so a typo is a recoverable mistake (sign in with the old password, fix
 * it again) rather than a locked-out account.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ChangeAccountEmailController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/upravit-profil/zmenit-email',
            'en' => '/en/edit-profile/change-email',
            'es' => '/es/editar-perfil/cambiar-correo',
            'ja' => '/ja/プロフィール編集/メールアドレス変更',
            'fr' => '/fr/modifier-profil/changer-email',
            'de' => '/de/profil-bearbeiten/e-mail-aendern',
        ],
        name: 'change_account_email',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, #[CurrentUser] UserAccount $user): Response
    {
        $data = new ChangeEmailFormData();
        $form = $this->createForm(ChangeEmailFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->messageBus->dispatch(
                    new ChangeAccountEmail(
                        userId: $user->getUserIdentifier(),
                        newEmail: $data->newEmail,
                        currentPassword: $data->currentPassword,
                    ),
                );
            } catch (HandlerFailedException $exception) {
                $reason = $exception->getPrevious();

                if ($reason instanceof CurrentPasswordDoesNotMatch) {
                    $form->get('currentPassword')->addError(
                        new FormError($this->translator->trans('edit_profile.change_password_wrong_current')),
                    );

                    return $this->renderPage($form, $user);
                }

                if ($reason instanceof EmailAlreadyRegistered) {
                    $form->get('newEmail')->addError(
                        new FormError($this->translator->trans('edit_profile.change_email_taken')),
                    );

                    return $this->renderPage($form, $user);
                }

                $this->logger->error('Changing the account email failed', [
                    'exception' => $exception,
                ]);

                $this->addFlash('danger', $this->translator->trans('edit_profile.change_email_failed'));

                // The form itself is valid, so render() would answer 200 - which Turbo Drive discards, flash included
                return $this->renderPage($form, $user, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Sent after the change is committed, so the token binds the new address
            $this->messageBus->dispatch(
                new SendEmailVerificationLink(
                    userId: $user->getUserIdentifier(),
                    fallbackLocale: $request->getLocale(),
                ),
            );

            $this->addFlash('success', $this->translator->trans('edit_profile.change_email_saved'));

            return $this->redirectToRoute('edit_profile');
        }

        return $this->renderPage($form, $user);
    }

    /**
     * @param FormInterface<ChangeEmailFormData> $form
     */
    private function renderPage(FormInterface $form, UserAccount $userAccount, int $status = Response::HTTP_OK): Response
    {
        // The form itself, not its view: render() answers 422 for a submitted invalid form, and Turbo Drive
        // silently discards a 200 answer to a form submission - field errors included
        return $this->render('change_account_email.html.twig', [
            'form' => $form,
            'current_email' => $userAccount->email,
        ], new Response(status: $status));
    }
}
