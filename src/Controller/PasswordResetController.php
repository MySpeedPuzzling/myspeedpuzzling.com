<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Exceptions\InvalidPasswordResetToken;
use SpeedPuzzling\Web\Exceptions\PasswordResetTokenExpired;
use SpeedPuzzling\Web\FormData\ResetPasswordFormData;
use SpeedPuzzling\Web\FormType\ResetPasswordFormType;
use SpeedPuzzling\Web\Message\ResetPassword;
use SpeedPuzzling\Web\Security\LoginFormAuthenticator;
use SpeedPuzzling\Web\Security\UserAccountProvider;
use SpeedPuzzling\Web\Services\ValidatePasswordResetToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Consumes a password reset token (issue #147). Anonymous by design - the token
 * is the proof, and the link gets opened wherever the mail is read.
 *
 * The token rides in the URL and no session is started for it (the #164
 * anonymous-cacheability constraint: a session cookie here would follow the
 * visitor across every later page). Referrer-Policy: no-referrer closes the one
 * hole that buys - the token leaking to anything the page links out to.
 *
 * A successful reset signs the browser in (auth UX redesign, owner decision D4):
 * whoever holds a live token controls the mailbox, which is exactly what an
 * emailed sign-in link already accepts as proof - sending them to /login to type
 * the password they chose seconds ago added friction, not security.
 */
final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatePasswordResetToken $validatePasswordResetToken,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly Security $security,
        private readonly UserAccountProvider $userAccountProvider,
    ) {
    }

    #[Route(
        path: '/password-reset/{token}',
        name: 'password_reset',
        // Deliberately looser than the token's real shape (64 hex chars): a link the
        // mail client wrapped or truncated should reach the controller and get the
        // "this link does not work, here is a new one" page, not a bare 404
        requirements: ['token' => '[0-9a-zA-Z]{1,128}'],
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $token): Response
    {
        // Checked up front so a dead link says so immediately, instead of letting the
        // user pick a password and only then telling them it was wasted
        try {
            $userAccount = $this->validatePasswordResetToken->validate($token);
        } catch (PasswordResetTokenExpired) {
            return $this->renderDeadToken('expired');
        } catch (InvalidPasswordResetToken) {
            return $this->renderDeadToken('invalid');
        }

        $data = new ResetPasswordFormData();
        $form = $this->createForm(ResetPasswordFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $envelope = $this->messageBus->dispatch(
                    new ResetPassword(
                        token: $token,
                        plainPassword: $data->plainPassword,
                    ),
                );
            } catch (HandlerFailedException $exception) {
                $reason = $exception->getPrevious();

                if ($reason instanceof PasswordResetTokenExpired) {
                    return $this->renderDeadToken('expired');
                }

                if ($reason instanceof InvalidPasswordResetToken) {
                    return $this->renderDeadToken('invalid');
                }

                $this->logger->error('Password reset failed', [
                    'exception' => $exception,
                ]);

                $this->addFlash('danger', $this->translator->trans('auth.password_reset.failed'));

                // The form itself is valid, so render() would answer 200 - which Turbo Drive discards, flash included
                return $this->noReferrer($this->render('password_reset.html.twig', [
                    'form' => $form,
                    'account_email' => $userAccount->email,
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY)));
            }

            /** @var HandledStamp $handledStamp */
            $handledStamp = $envelope->last(HandledStamp::class);
            $userId = $handledStamp->getResult();
            assert(is_string($userId));

            // Signed in like any other login: the named authenticator (the firewall
            // carries several) and the always-on remember-me badge. LoginSuccessEvent
            // puts the sign-in into the audit log next to password_reset_completed.
            $this->security->login(
                $this->userAccountProvider->loadUserByIdentifier($userId),
                authenticatorName: LoginFormAuthenticator::class,
                firewallName: 'main',
                badges: [new RememberMeBadge()],
            );

            $this->addFlash('success', $this->translator->trans('auth.password_reset.done'));

            return $this->redirectToRoute('my_profile', status: Response::HTTP_SEE_OTHER);
        }

        return $this->noReferrer($this->render('password_reset.html.twig', [
            'form' => $form,
            // For the hidden username field: lets the password manager file the new
            // password under the right account. Not an enumeration leak - only the
            // holder of a live token for this account ever sees the page.
            'account_email' => $userAccount->email,
        ]));
    }

    private function renderDeadToken(string $outcome): Response
    {
        return $this->noReferrer($this->render('password_reset_dead_token.html.twig', [
            'outcome' => $outcome,
            'headline' => $this->translator->trans('auth.password_reset.' . $outcome . '.headline'),
            'message' => $this->translator->trans('auth.password_reset.' . $outcome . '.message'),
        ]));
    }

    private function noReferrer(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
