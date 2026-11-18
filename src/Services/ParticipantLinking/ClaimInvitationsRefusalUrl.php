<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantLinking;

use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The one-click "Don't send me more invitations for this event" of the claim invitation e-mail (docs/features/
 * competitions-management/participant-linking.md §9.1, R8) - the footer link and the RFC 8058 `List-Unsubscribe`
 * header. Signed (UriSigner), so it works without signing in and nobody refuses for somebody else by guessing an id;
 * never expires - the link of an old e-mail keeps working.
 */
readonly final class ClaimInvitationsRefusalUrl
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    public function forParticipant(string $participantId, string $locale): string
    {
        return $this->uriSigner->sign($this->urlGenerator->generate(
            'participant_claim_invitations_refuse',
            ['_locale' => $locale, 'participantId' => strtolower($participantId)],
            UrlGeneratorInterface::ABSOLUTE_URL,
        ));
    }
}
