<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use SpeedPuzzling\Web\Message\RequestSignInLink;
use SpeedPuzzling\Web\Services\CheckEmailFlash;
use SpeedPuzzling\Web\Services\SignInCodePending;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Email me a sign-in code" (D6, issue #147): the rescue for everybody without
 * a usable password - forgotten, or filed by their password manager under the
 * old Auth0 sign-in domain. The mail carries a link and a 6-digit code (auth UX
 * redesign phase 2); the code is bound to this browser (SignInCodePending).
 *
 * Success is its own screen (/login-link/sent, SignInLinkSentController): the
 * address travels there in a flash (CheckEmailFlash), never in a URL. Anything that
 * went wrong re-renders this form with 422 (Turbo Drive drops a 200 answer to
 * a form POST).
 *
 * The answer is the same whether or not the address has an account - the page
 * never reveals who is registered (D8 enumeration tradeoff).
 */
final class SignInLinkController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'sign_in_link';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterFactoryInterface $signInLinkEmailLimiter,
        private readonly RateLimiterFactoryInterface $signInLinkIpLimiter,
        private readonly SignInCodePending $signInCodePending,
    ) {
    }

    #[Route(
        path: '/login-link',
        name: 'sign_in_link_request',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request): Response
    {
        $returnUrl = ReturnUrl::tryFrom($request->isMethod('POST')
            ? $request->request->getString('return')
            : $request->query->getString('return'));

        if ($request->isMethod('POST') === false) {
            return $this->renderForm('', $returnUrl);
        }

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $email = trim((string) $request->request->get('email'));

        if ($email === '') {
            return $this->renderForm($email, $returnUrl, fieldError: 'auth.sign_in_link.email_required');
        }

        if (count($this->validator->validate($email, new Email())) > 0) {
            return $this->renderForm($email, $returnUrl, fieldError: 'auth.check_email.email_invalid');
        }

        if ($this->consumeRateLimit($email, $request->getClientIp()) === false) {
            return $this->renderForm($email, $returnUrl, alert: 'auth.sign_in_link.too_many_requests');
        }

        // Chosen here, not in the handler: this browser keeps it to check the
        // e-mailed 6-digit code against - also when no mail goes out (D8)
        $requestId = Uuid::uuid7();

        try {
            $this->messageBus->dispatch(
                new RequestSignInLink(
                    email: $email,
                    fallbackLocale: $request->getLocale(),
                    returnPath: $returnUrl?->path,
                    requestId: $requestId,
                ),
            );
        } catch (HandlerFailedException $exception) {
            $this->logger->error('Could not issue a sign-in link', [
                'exception' => $exception,
            ]);

            return $this->renderForm($email, $returnUrl, alert: 'auth.sign_in_link.failed');
        }

        // Deliberately identical for known and unknown addresses
        CheckEmailFlash::add($request, CheckEmailFlash::SIGN_IN_LINK, $email, $request->request->getBoolean('resend'));
        $this->signInCodePending->start($request, $requestId, $email);

        return $this->redirectToRoute(
            'sign_in_link_sent',
            $returnUrl === null ? [] : ['return' => $returnUrl->path],
            Response::HTTP_SEE_OTHER,
        );
    }

    private function renderForm(
        string $email,
        null|ReturnUrl $returnUrl,
        null|string $fieldError = null,
        null|string $alert = null,
    ): Response {
        $failed = $fieldError !== null || $alert !== null;

        return $this->render('sign_in_link.html.twig', [
            'email' => $email,
            'return_path' => $returnUrl?->path,
            'field_error' => $fieldError === null ? null : $this->translator->trans($fieldError),
            'alert' => $alert === null ? null : $this->translator->trans($alert),
        ], new Response(status: $failed ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Unauthenticated endpoint that sends mail to an address the caller picks:
     * throttled per address (mail cannon aimed at one inbox) and per client IP
     * (many addresses from one place).
     */
    private function consumeRateLimit(string $email, null|string $clientIp): bool
    {
        $perEmail = $this->signInLinkEmailLimiter
            ->create(UserAccount::canonicalizeEmail($email))
            ->consume();

        $perIp = $this->signInLinkIpLimiter
            ->create($clientIp ?? 'unknown')
            ->consume();

        return $perEmail->isAccepted() && $perIp->isAccepted();
    }
}
