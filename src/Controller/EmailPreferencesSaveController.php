<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\InvalidNewsletterToken;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\EditEmailPreferences;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Value\EmailNotificationFrequency;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class EmailPreferencesSaveController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'email-preferences';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly NewsletterTokenSigner $tokenSigner,
        private readonly GetPlayerProfile $getPlayerProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/nastaveni-emailu/{token}/ulozit',
            'en' => '/en/email-preferences/{token}/save',
            'es' => '/es/preferencias-de-email/{token}/guardar',
            'ja' => '/ja/email-preferences/{token}/save',
            'fr' => '/fr/preferences-email/{token}/enregistrer',
            'de' => '/de/e-mail-einstellungen/{token}/speichern',
        ],
        name: 'email_preferences_save',
        methods: ['POST'],
    )]
    public function __invoke(string $token, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('email_preferences', ['token' => $token]);
        }

        try {
            $claim = $this->tokenSigner->parsePreferencesToken($token);

            if ($claim->audience !== NewsletterAudience::Player) {
                throw new InvalidNewsletterToken();
            }

            $profile = $this->getPlayerProfile->byId($claim->id);

            if ($profile->email === null || mb_strtolower(trim($profile->email)) !== $claim->email) {
                throw new InvalidNewsletterToken();
            }
        } catch (InvalidNewsletterToken | PlayerNotFound) {
            return $this->render('newsletter/invalid-token.html.twig', [
                'messageKey' => 'newsletter.invalid.invalid_text',
            ], new Response(status: Response::HTTP_NOT_FOUND));
        }

        $frequency = EmailNotificationFrequency::tryFrom((string) $request->request->get('email_notification_frequency'))
            ?? $profile->emailNotificationFrequency;

        $this->messageBus->dispatch(
            new EditEmailPreferences(
                playerId: $claim->id,
                newsletterEnabled: $request->request->getBoolean('newsletter_enabled'),
                emailNotificationsEnabled: $request->request->getBoolean('email_notifications_enabled'),
                emailNotificationFrequency: $frequency,
            ),
        );

        return $this->redirectToRoute('email_preferences', ['token' => $token, 'saved' => 1]);
    }
}
