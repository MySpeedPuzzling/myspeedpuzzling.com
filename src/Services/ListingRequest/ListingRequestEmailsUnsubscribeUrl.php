<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ListingRequest;

use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The one-click unsubscribe link of the sell/swap request e-mails (`List-Unsubscribe`, RFC 8058,
 * docs/features/marketplace/12-listing-requests.md): signed, so it works without signing in and nobody can switch off somebody
 * else's e-mails by guessing. Never expires. Same pattern as PartnerFinderEmailsUnsubscribeUrl.
 */
readonly final class ListingRequestEmailsUnsubscribeUrl
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    public function forPlayer(string $playerId, null|string $locale): string
    {
        return $this->uriSigner->sign($this->urlGenerator->generate(
            'listing_request_emails_unsubscribe',
            [
                '_locale' => ListmonkNewsletterLists::normalizeLocale($locale),
                'playerId' => $playerId,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        ));
    }
}
