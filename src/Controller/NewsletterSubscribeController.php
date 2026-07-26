<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\SubscribeToNewsletter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Public newsletter signup from the site footer (guests, double opt-in).
 */
final class NewsletterSubscribeController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'newsletter-subscribe';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
        private readonly RateLimiterFactoryInterface $newsletterSubscribeEmailLimiter,
        private readonly RateLimiterFactoryInterface $newsletterSubscribeIpLimiter,
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
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('warning', $this->translator->trans('newsletter.flash.try_again'));

            return $this->redirectBack($request);
        }

        $email = trim((string) $request->request->get('email'));

        $violations = $this->validator->validate($email, [new NotBlank(), new Email()]);

        if (count($violations) > 0) {
            $this->addFlash('warning', $this->translator->trans('newsletter.flash.invalid_email'));

            return $this->redirectBack($request);
        }

        $emailLimit = $this->newsletterSubscribeEmailLimiter->create(mb_strtolower($email))->consume();
        $ipLimit = $this->newsletterSubscribeIpLimiter->create($request->getClientIp() ?? 'unknown')->consume();

        if ($emailLimit->isAccepted() === false || $ipLimit->isAccepted() === false) {
            $this->addFlash('warning', $this->translator->trans('newsletter.flash.too_many_attempts'));

            return $this->redirectBack($request);
        }

        $this->messageBus->dispatch(new SubscribeToNewsletter(
            email: $email,
            locale: $request->getLocale(),
            ipAddress: $request->getClientIp(),
        ));

        return $this->redirectToRoute('newsletter_check_inbox');
    }

    private function redirectBack(Request $request): RedirectResponse
    {
        $referer = $request->headers->get('referer');

        if ($referer !== null && str_starts_with($referer, $request->getSchemeAndHttpHost() . '/')) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('homepage');
    }
}
