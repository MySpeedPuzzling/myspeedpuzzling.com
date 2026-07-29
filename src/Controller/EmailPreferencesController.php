<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\InvalidNewsletterToken;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Value\EmailNotificationFrequency;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Standalone e-mail preferences page, reached only from links in our e-mails
 * (newsletter footer, digest opt-out, unsubscribe page). Token-authenticated,
 * so it works without signing in - and unlike the crowded edit-profile page it
 * shows just the e-mail related settings. The token stops working the moment
 * the player's e-mail address changes.
 */
final class EmailPreferencesController extends AbstractController
{
    public function __construct(
        private readonly NewsletterTokenSigner $tokenSigner,
        private readonly GetPlayerProfile $getPlayerProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/nastaveni-emailu/{token}',
            'en' => '/en/email-preferences/{token}',
            'es' => '/es/preferencias-de-email/{token}',
            'ja' => '/ja/email-preferences/{token}',
            'fr' => '/fr/preferences-email/{token}',
            'de' => '/de/e-mail-einstellungen/{token}',
        ],
        name: 'email_preferences',
        methods: ['GET'],
    )]
    public function __invoke(string $token, Request $request): Response
    {
        try {
            $claim = $this->tokenSigner->parsePreferencesToken($token);

            if ($claim->audience !== NewsletterAudience::Player) {
                throw new InvalidNewsletterToken();
            }

            $profile = $this->getPlayerProfile->byId($claim->id);

            // The link in an already-sent e-mail must die when the address changes
            if ($profile->email === null || mb_strtolower(trim($profile->email)) !== $claim->email) {
                throw new InvalidNewsletterToken();
            }
        } catch (InvalidNewsletterToken | PlayerNotFound) {
            return $this->render('newsletter/invalid-token.html.twig', [
                'messageKey' => 'newsletter.invalid.invalid_text',
            ], new Response(status: Response::HTTP_NOT_FOUND));
        }

        $response = $this->render('newsletter/email-preferences.html.twig', [
            'token' => $token,
            'email' => $profile->email,
            'newsletterEnabled' => $profile->newsletterEnabled,
            'emailNotificationsEnabled' => $profile->emailNotificationsEnabled,
            'emailNotificationFrequency' => $profile->emailNotificationFrequency,
            'frequencies' => EmailNotificationFrequency::cases(),
            'saved' => $request->query->getBoolean('saved'),
        ]);

        // Personal settings behind a capability URL: a shared cache must never
        // store them (and must not serve a stale state right after saving)
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
