<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a provider proved about the visitor, normalized across Google,
 * Microsoft, Apple and Facebook. Trust policy (2026-09-29): the provider email is trusted
 * unless the provider explicitly marks it unverified. `emailVerified` is what
 * that policy concluded, per provider (SocialProfileFetcher):
 *
 * - Google: the `email_verified` claim must be exactly true (any domain).
 * - Facebook: an email that is present is trusted - the Graph API returns only
 *   confirmed addresses; a denied email permission arrives as a null email.
 * - Apple: read from the validated id_token claims - `email_verified` may come
 *   as a boolean or as the string "true"/"false"; only true/"true" count. An
 *   email Apple explicitly marks unverified is kept (rule 4 still registers
 *   the account, unverified, and asks for confirmation) but never auto-links.
 * - Microsoft: Microsoft documents the `email` claim as "not guaranteed to be
 *   correct", so it counts as verified only on Microsoft's own consumer
 *   mailbox domains (MicrosoftConsumerMailDomains - there the account IS the
 *   mailbox); any other address is unverified (never auto-links, rule 4 asks
 *   for confirmation).
 *
 * `emailVerified` drives both the auto-link decision (rule 2 vs 3) and whether
 * a rule-4 account starts verified.
 */
final readonly class SocialUserProfile
{
    public function __construct(
        public OauthProvider $provider,
        public string $providerUserId,
        public null|string $email,
        public bool $emailVerified,
        public null|string $name,
        // Apple "Hide My Email": the address is an @privaterelay.appleid.com
        // forwarder (`is_private_email` claim). Always false for other providers.
        public bool $isPrivateRelay = false,
    ) {
    }

    /**
     * The claim, or - should Apple ever omit it - the relay domain itself.
     * A relay address never matches an existing account, so the rule-4
     * interstitial pushes "I already have an account" for these visitors.
     */
    public function usesPrivateRelay(): bool
    {
        return $this->isPrivateRelay
            || ($this->email !== null && str_ends_with(strtolower($this->email), '@privaterelay.appleid.com'));
    }
}
