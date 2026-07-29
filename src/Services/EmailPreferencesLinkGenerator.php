<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Absolute URL of the standalone e-mail preferences page for a player - the
 * "manage my notification settings" target used in every e-mail we send
 * (newsletter footer, digest opt-out, unsubscribe page). Token-authenticated,
 * so it works without signing in; deterministic, so the Listmonk sync can diff
 * subscriber attributes without churn.
 */
readonly final class EmailPreferencesLinkGenerator
{
    public function __construct(
        private NewsletterTokenSigner $tokenSigner,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function forPlayer(string $playerId, string $email, null|string $locale): string
    {
        $token = $this->tokenSigner->generatePreferencesToken(NewsletterAudience::Player, $playerId, $email);

        return $this->urlGenerator->generate(
            'email_preferences',
            [
                '_locale' => ListmonkNewsletterLists::normalizeLocale($locale),
                'token' => $token,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
