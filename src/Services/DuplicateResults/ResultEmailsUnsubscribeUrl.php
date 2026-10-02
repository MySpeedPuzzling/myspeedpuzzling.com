<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DuplicateResults;

use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The one-click unsubscribe link of the "Your results" e-mail (`List-Unsubscribe`, RFC 8058): signed, so it works
 * without signing in and nobody can switch off somebody else's e-mails by guessing. Never expires - an unsubscribe
 * link in an old e-mail must keep working.
 */
readonly final class ResultEmailsUnsubscribeUrl
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    public function forPlayer(string $playerId, null|string $locale): string
    {
        return $this->uriSigner->sign($this->urlGenerator->generate(
            'result_emails_unsubscribe',
            [
                '_locale' => ListmonkNewsletterLists::normalizeLocale($locale),
                'playerId' => $playerId,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        ));
    }
}
