<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\TurnOnNewsletter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Turn it on" in the footer, shown only to players who switched the newsletter off.
 */
final class NewsletterTurnOnController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'newsletter-turn-on';

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/newsletter/zapnout',
            'en' => '/en/newsletter/turn-on',
            'es' => '/es/newsletter/activar',
            'ja' => '/ja/newsletter/turn-on',
            'fr' => '/fr/newsletter/activer',
            'de' => '/de/newsletter/aktivieren',
        ],
        name: 'newsletter_turn_on',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('warning', $this->translator->trans('newsletter.flash.try_again'));

            return $this->redirectBack($request);
        }

        $this->messageBus->dispatch(new TurnOnNewsletter(playerId: $loggedPlayer->playerId));

        $this->addFlash('success', $this->translator->trans('newsletter.flash.turned_on'));

        return $this->redirectBack($request);
    }

    private function redirectBack(Request $request): RedirectResponse
    {
        $referer = $request->headers->get('referer');

        if ($referer !== null && str_starts_with($referer, $request->getSchemeAndHttpHost() . '/')) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('hub');
    }
}
