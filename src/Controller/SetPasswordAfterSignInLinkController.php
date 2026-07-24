<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\FormData\SetPasswordFormData;
use SpeedPuzzling\Web\FormType\SetPasswordFormType;
use SpeedPuzzling\Web\Message\SetAccountPassword;
use SpeedPuzzling\Web\Security\SignInLinkPasswordPrompt;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one-time prompt that follows a magic-link login (UX funnel §5, issue #147):
 * "set a fresh password so your manager saves it under myspeedpuzzling.com".
 *
 * Skipping is a first-class outcome - the old password keeps working - so the
 * page is offered exactly once, gated by the session flag the login-link success
 * handler writes. Without that flag this is not a page at all: setting a password
 * without proving the current one may only happen in the moments right after the
 * user proved control of their mailbox.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class SetPasswordAfterSignInLinkController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/set-password',
        name: 'set_password_after_sign_in_link',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, #[CurrentUser] UserAccount $userAccount): Response
    {
        $session = $request->getSession();

        if ($session->get(SignInLinkPasswordPrompt::SESSION_KEY) !== true) {
            return $this->redirectToRoute('my_profile');
        }

        // "Not now - my current password keeps working"
        if ($request->query->getBoolean('skip')) {
            $session->remove(SignInLinkPasswordPrompt::SESSION_KEY);

            return $this->redirectToRoute('my_profile');
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

            $session->remove(SignInLinkPasswordPrompt::SESSION_KEY);

            $this->addFlash('success', $this->translator->trans('auth.set_password.saved'));

            return $this->redirectToRoute('my_profile');
        }

        return $this->render('set_password_after_sign_in_link.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
