<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Message\RequestPasswordReset;
use SpeedPuzzling\Web\Message\SendPasswordResetLink;
use SpeedPuzzling\Web\Services\CheckEmailFlash;
use SpeedPuzzling\Web\Value\PasswordResetToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Forgot password?" (issue #147). Answers identically whether or not the
 * address has an account - the page must never become a way to probe who is
 * registered (D8 enumeration tradeoff, mirrors the sign-in link endpoint).
 * Success is its own "Check your email" screen (PasswordResetSentController).
 */
final class RequestPasswordResetController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'request_password_reset';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterFactoryInterface $passwordResetEmailLimiter,
        private readonly RateLimiterFactoryInterface $passwordResetIpLimiter,
    ) {
    }

    #[Route(
        path: '/password-reset',
        name: 'request_password_reset',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST') === false) {
            return $this->renderForm('');
        }

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $email = trim((string) $request->request->get('email'));

        if ($email === '') {
            return $this->renderForm($email, fieldError: 'auth.password_reset.email_required');
        }

        if (count($this->validator->validate($email, new Email())) > 0) {
            return $this->renderForm($email, fieldError: 'auth.check_email.email_invalid');
        }

        if ($this->consumeRateLimit($email, $request->getClientIp()) === false) {
            return $this->renderForm($email, alert: 'auth.password_reset.too_many_requests');
        }

        try {
            $envelope = $this->messageBus->dispatch(
                new RequestPasswordReset(email: $email),
            );

            /** @var HandledStamp $handledStamp */
            $handledStamp = $envelope->last(HandledStamp::class);
            $token = $handledStamp->getResult();
            assert($token === null || $token instanceof PasswordResetToken);

            // null means an unknown address - silent
            if ($token !== null) {
                $this->messageBus->dispatch(
                    new SendPasswordResetLink(
                        email: $email,
                        token: $token->toString(),
                        fallbackLocale: $request->getLocale(),
                    ),
                );
            }
        } catch (HandlerFailedException $exception) {
            $this->logger->error('Could not issue a password reset link', [
                'exception' => $exception,
            ]);

            return $this->renderForm($email, alert: 'auth.password_reset.failed');
        }

        // Deliberately identical for known and unknown addresses. The address rides
        // to the next screen in a flash, never in the URL.
        CheckEmailFlash::add($request, CheckEmailFlash::PASSWORD_RESET, $email, $request->request->getBoolean('resend'));

        return $this->redirectToRoute('password_reset_sent', status: Response::HTTP_SEE_OTHER);
    }

    private function renderForm(string $email, null|string $fieldError = null, null|string $alert = null): Response
    {
        $failed = $fieldError !== null || $alert !== null;

        // A form POST answered 200 is dropped by Turbo Drive: every failure is a 422
        return $this->render('request_password_reset.html.twig', [
            'email' => $email,
            'field_error' => $fieldError === null ? null : $this->translator->trans($fieldError),
            'alert' => $alert === null ? null : $this->translator->trans($alert),
        ], new Response(status: $failed ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function consumeRateLimit(string $email, null|string $clientIp): bool
    {
        $perEmail = $this->passwordResetEmailLimiter
            ->create(UserAccount::canonicalizeEmail($email))
            ->consume();

        $perIp = $this->passwordResetIpLimiter
            ->create($clientIp ?? 'unknown')
            ->consume();

        return $perEmail->isAccepted() && $perIp->isAccepted();
    }
}
