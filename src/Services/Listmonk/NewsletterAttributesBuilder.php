<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Listmonk;

use SpeedPuzzling\Web\Results\NewsletterRecipient;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the Listmonk subscriber attributes the campaign template relies on:
 * locale (footer localization), audience, the per-recipient MySpeedPuzzling
 * unsubscribe URL and - for players - the notification-settings URL. All values
 * are deterministic so the sync can diff them without churn.
 */
readonly final class NewsletterAttributesBuilder
{
    public function __construct(
        private NewsletterTokenSigner $tokenSigner,
        private UrlGeneratorInterface $urlGenerator,
        private EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function build(NewsletterRecipient $recipient): array
    {
        $locale = ListmonkNewsletterLists::normalizeLocale($recipient->locale);

        $unsubscribeToken = $this->tokenSigner->generateUnsubscribeToken(
            $recipient->audience,
            $recipient->id,
            $recipient->email,
        );

        $attributes = [
            'locale' => $locale,
            'audience' => $recipient->audience->value,
            'unsubscribe_url' => $this->urlGenerator->generate(
                'newsletter_unsubscribe',
                ['_locale' => $locale, 'token' => $unsubscribeToken],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        ];

        if ($recipient->audience === NewsletterAudience::Player) {
            $attributes['manage_url'] = $this->emailPreferencesLinkGenerator->forPlayer(
                $recipient->id,
                $recipient->email,
                $locale,
            );
        }

        return $attributes;
    }
}
