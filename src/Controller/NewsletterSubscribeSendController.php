<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\SubscribeToNewsletter;
use SpeedPuzzling\Web\Services\NewsletterSignupTicket;
use SpeedPuzzling\Web\Services\Turnstile\TurnstileVerifier;
use SpeedPuzzling\Web\Value\NewsletterSignupTicketCheck;
use SpeedPuzzling\Web\Value\TurnstileVerdict;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Newsletter signup, step 2 of 2 (step 1: NewsletterSubscribeController): "Send the confirmation e-mail" - the only
 * place that mails a guest's address. In this order: honeypot (filled = answered like a success, nothing sent), the
 * step's ticket (shown at least NewsletterSignupTicket::MINIMUM_SECONDS ago), Turnstile (when configured), the
 * per-address and per-IP limits - consumed only now, so a bot failing the checks never uses up a real person's
 * attempts. Every refusal a person could hit shows the step again (422) with a fresh ticket and a reason.
 *
 * A native form post (data-turbo=false) on purpose: the answer is a whole page either way, and the Turnstile
 * token is single use - a new page renders a new widget.
 */
final class NewsletterSubscribeSendController extends AbstractController
{
    public const string TURNSTILE_ACTION = 'newsletter';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatorInterface $validator,
        private readonly NewsletterSignupTicket $signupTicket,
        private readonly TurnstileVerifier $turnstile,
        private readonly RateLimiterFactoryInterface $newsletterSubscribeEmailLimiter,
        private readonly RateLimiterFactoryInterface $newsletterSubscribeIpLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/newsletter/prihlasit-odber/odeslat',
            'en' => '/en/newsletter/subscribe/send',
            'es' => '/es/newsletter/suscribirse/enviar',
            'ja' => '/ja/newsletter/subscribe/send',
            'fr' => '/fr/newsletter/inscription/envoyer',
            'de' => '/de/newsletter/anmelden/senden',
        ],
        name: 'newsletter_subscribe_send',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): Response
    {
        if ((string) $request->request->get(NewsletterSubscribeController::HONEYPOT_FIELD) !== '') {
            $this->logger->info('Newsletter signup refused: honeypot filled', ['step' => 'send']);

            return $this->redirectToRoute('newsletter_check_inbox', status: Response::HTTP_SEE_OTHER);
        }

        $email = trim((string) $request->request->get('email'));

        if (!$this->isCsrfTokenValid(NewsletterSubscribeController::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            return $this->showStepAgain($email, 'newsletter.flash.try_again');
        }

        if (count($this->validator->validate($email, [new NotBlank(), new Email()])) > 0) {
            return $this->showStepAgain($email, 'newsletter.flash.invalid_email');
        }

        $ticketCheck = $this->signupTicket->check((string) $request->request->get('ticket'));

        if ($ticketCheck !== NewsletterSignupTicketCheck::Valid) {
            $this->logger->info('Newsletter signup refused: ticket', ['check' => $ticketCheck->name]);

            return $this->showStepAgain($email, 'newsletter.signup.error_confirm_again');
        }

        if ($this->turnstile->isOn()) {
            $verdict = $this->turnstile->verify(
                (string) $request->request->get('cf-turnstile-response'),
                self::TURNSTILE_ACTION,
                $request->getHost(),
                $request->getClientIp(),
            );

            if ($verdict !== TurnstileVerdict::Passed) {
                return $this->showStepAgain($email, $verdict === TurnstileVerdict::Unavailable
                    ? 'newsletter.signup.error_check_unavailable'
                    : 'newsletter.signup.error_check_failed');
            }
        }

        $emailLimit = $this->newsletterSubscribeEmailLimiter->create(mb_strtolower($email))->consume();
        $ipLimit = $this->newsletterSubscribeIpLimiter->create($request->getClientIp() ?? 'unknown')->consume();

        if ($emailLimit->isAccepted() === false || $ipLimit->isAccepted() === false) {
            return $this->showStepAgain($email, 'newsletter.flash.too_many_attempts');
        }

        $this->messageBus->dispatch(new SubscribeToNewsletter(
            email: $email,
            locale: $request->getLocale(),
            ipAddress: $request->getClientIp(),
        ));

        return $this->redirectToRoute('newsletter_check_inbox', status: Response::HTTP_SEE_OTHER);
    }

    private function showStepAgain(string $email, string $error): Response
    {
        return $this->render('newsletter/signup-step.html.twig', [
            'email' => $email,
            'ticket' => $this->signupTicket->issue(),
            'turnstileSiteKey' => $this->turnstile->isOn() ? $this->turnstile->siteKey() : null,
            'error' => $error,
        ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
