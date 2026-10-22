<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PartnerFinder;

use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The one-click unsubscribe link of the partner finder e-mails (`List-Unsubscribe`, RFC 8058,
 * docs/features/partner-finder.md D6): signed, so it works without signing in and nobody can switch off somebody else's
 * e-mails by guessing. Never expires - an unsubscribe link in an old e-mail must keep working. Same pattern as
 * DigestEmailsUnsubscribeUrl.
 */
readonly final class PartnerFinderEmailsUnsubscribeUrl
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    public function forPlayer(string $playerId, null|string $locale): string
    {
        return $this->uriSigner->sign($this->urlGenerator->generate(
            'partner_finder_emails_unsubscribe',
            [
                '_locale' => ListmonkNewsletterLists::normalizeLocale($locale),
                'playerId' => $playerId,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        ));
    }
}
