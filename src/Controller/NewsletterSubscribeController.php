<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Services\NewsletterSignupTicket;
use SpeedPuzzling\Web\Services\Turnstile\TurnstileVerifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Public newsletter signup from the site footer (guests, double opt-in), step 1 of 2: sends nothing. It answers
 * the "Send the confirmation e-mail to …?" step - a modal (the footer form targets `modal-frame`), a page without
 * JavaScript - and only that step's form (NewsletterSubscribeSendController) mails anybody.
 *
 * Bots typed other people's addresses into the footer form (docs/features/newsletter/README.md "Bots"): every
 * confirmation to an address that never asked for it is a bounce or a spam complaint against our sending
 * reputation. A filled honeypot is answered like a success, so a bot learns nothing.
 */
final class NewsletterSubscribeController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'newsletter-subscribe';
    public const string HONEYPOT_FIELD = 'homepage';

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly NewsletterSignupTicket $signupTicket,
        private readonly TurnstileVerifier $turnstile,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/newsletter/prihlasit-odber',
            'en' => '/en/newsletter/subscribe',
            'es' => '/es/newsletter/suscribirse',
            'ja' => '/ja/newsletter/subscribe',
            'fr' => '/fr/newsletter/inscription',
            'de' => '/de/newsletter/anmelden',
        ],
        name: 'newsletter_subscribe',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): Response
    {
        if ((string) $request->request->get(self::HONEYPOT_FIELD) !== '') {
            $this->logger->info('Newsletter signup refused: honeypot filled', ['step' => 'footer']);

            return $this->redirectToRoute('newsletter_check_inbox', status: Response::HTTP_SEE_OTHER);
        }

        $email = trim((string) $request->request->get('email'));
        $error = null;

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            $error = 'newsletter.flash.try_again';
        } elseif (count($this->validator->validate($email, [new NotBlank(), new Email()])) > 0) {
            $error = 'newsletter.flash.invalid_email';
        }

        $isModal = $request->headers->get('Turbo-Frame') === 'modal-frame';

        return $this->render($isModal ? 'newsletter/_signup_step_modal.html.twig' : 'newsletter/signup-step.html.twig', [
            'email' => $email,
            'ticket' => $this->signupTicket->issue(),
            'turnstileSiteKey' => $this->turnstile->isOn() ? $this->turnstile->siteKey() : null,
            'error' => $error,
        ]);
    }
}
