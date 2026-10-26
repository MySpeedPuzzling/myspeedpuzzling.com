<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\BorrowRequest;

use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The one-click unsubscribe link of the borrow request e-mails (`List-Unsubscribe`, RFC 8058,
 * docs/features/wishlist-availability.md): signed, so it works without signing in and nobody can switch off somebody
 * else's e-mails by guessing. Never expires. Same pattern as PartnerFinderEmailsUnsubscribeUrl.
 */
readonly final class BorrowRequestEmailsUnsubscribeUrl
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    public function forPlayer(string $playerId, null|string $locale): string
    {
        return $this->uriSigner->sign($this->urlGenerator->generate(
            'borrow_request_emails_unsubscribe',
            [
                '_locale' => ListmonkNewsletterLists::normalizeLocale($locale),
                'playerId' => $playerId,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        ));
    }
}
