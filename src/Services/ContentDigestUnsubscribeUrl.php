<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The one-click unsubscribe link of the weekly content digest (`List-Unsubscribe`, RFC 8058) - and of the XP reveal
 * e-mail, its one-off sibling: signed, so it works without signing in and nobody can switch off somebody else's
 * e-mails by guessing. Never expires - an unsubscribe link in an old e-mail must keep working. Same pattern as the
 * unread-messages digest (DigestEmailsUnsubscribeUrl).
 */
readonly final class ContentDigestUnsubscribeUrl
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    public function forPlayer(string $playerId, null|string $locale): string
    {
        return $this->uriSigner->sign($this->urlGenerator->generate(
            'content_digest_unsubscribe',
            [
                '_locale' => ListmonkNewsletterLists::normalizeLocale($locale),
                'playerId' => $playerId,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        ));
    }
}
