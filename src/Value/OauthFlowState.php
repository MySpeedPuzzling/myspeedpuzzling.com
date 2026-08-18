<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Server-side OAuth state payload (cache-backed, single-use). Session storage
 * is not an option: Apple's form_post callback is a cross-site POST that
 * arrives without SameSite=Lax session cookies, and the anonymous start route
 * must not create sessions (#164).
 */
final readonly class OauthFlowState
{
    public function __construct(
        public OauthProvider $provider,
        public OauthFlowIntent $intent,
        public null|string $pkceVerifier = null,
        // Link intent only: the authenticated account the identity attaches to
        public null|string $userId = null,
        // Login intent only: where the visitor was headed before signing in,
        // already validated by ReturnUrl. It rides here rather than in the
        // session for the same reason everything else does - Apple's callback
        // arrives without SameSite=Lax cookies, so a session written at the
        // start of the flow is simply unreadable when the provider comes back.
        public null|string $returnUrl = null,
    ) {
    }
}
