<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\FormData\SetPasswordFormData;
use SpeedPuzzling\Web\FormType\SetPasswordFormType;
use SpeedPuzzling\Web\Message\SetAccountPassword;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The set-password door for social-only accounts (password IS NULL): opens the
 * email+password sign-in method from "Connected sign-in methods". Reuses the
 * SetAccountPassword message - no current-password proof exists to ask for,
 * and ownership is proven by the authenticated session (the account was
 * entered through a provider-verified identity). Accounts that already HAVE a
 * password keep the change-password flow, which demands the current one.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AccountSetPasswordController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/account/set-password',
        name: 'account_set_password',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, #[CurrentUser] UserAccount $userAccount): Response
    {
        if ($userAccount->password !== null) {
            return $this->redirectToRoute('change_account_password');
        }

        $data = new SetPasswordFormData();
        $form = $this->createForm(SetPasswordFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->messageBus->dispatch(
                new SetAccountPassword(
                    userId: $userAccount->getUserIdentifier(),
                    plainPassword: $data->plainPassword,
                ),
            );

            $this->addFlash('success', $this->translator->trans('auth.set_password.saved'));

            return $this->redirectToRoute('edit_profile');
        }

        return $this->render('account_set_password.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
