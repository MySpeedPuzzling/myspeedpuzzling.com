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
use SpeedPuzzling\Web\Services\ValidatePasswordResetToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Consumes a password reset token (issue #147). Anonymous by design - the token
 * is the proof, and the link gets opened wherever the mail is read.
 *
 * The token rides in the URL and no session is started for it (the #164
 * anonymous-cacheability constraint: a session cookie here would follow the
 * visitor across every later page). Referrer-Policy: no-referrer closes the one
 * hole that buys - the token leaking to anything the page links out to.
 */
final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatePasswordResetToken $validatePasswordResetToken,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
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
            $this->validatePasswordResetToken->validate($token);
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
                $this->messageBus->dispatch(
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

                return $this->noReferrer($this->render('password_reset.html.twig', [
                    'form' => $form->createView(),
                ]));
            }

            // Not logged in here on purpose: proving control of the mailbox resets the
            // password, it does not authenticate the browser that opened the link
            $this->addFlash('success', $this->translator->trans('auth.password_reset.done'));

            return $this->redirectToRoute('login');
        }

        return $this->noReferrer($this->render('password_reset.html.twig', [
            'form' => $form->createView(),
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
