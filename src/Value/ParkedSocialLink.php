<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A provider profile waiting to be linked to an account, parked in the
 * short-TTL social login cache. Linking never happens in the OAuth callback
 * itself: the callback cannot tell WHO is finishing the flow (Apple's
 * cross-site POST carries no cookies at all), so an attacker could start a
 * connect flow from their own account and trick a victim into completing the
 * consent - a forged connect that attaches the victim's provider identity to
 * the attacker's account.
 *
 * The finish route (SocialLinkFinishController) links only when the visitor
 * holding the token satisfies every binding set here:
 *
 * - `targetUserId`: the account that started the settings connect flow must
 *   be the one signed in now;
 * - `browserBindingHash`: sha256 of the nonce cookie set on the browser that
 *   parked the profile (the rule-4 interstitial "sign in and connect" path,
 *   where the account is not known yet).
 *
 * At least one binding is always present.
 */
final readonly class ParkedSocialLink
{
    public function __construct(
        public SocialUserProfile $profile,
        public null|string $targetUserId,
        public null|string $browserBindingHash,
    ) {
        assert($targetUserId !== null || $browserBindingHash !== null);
    }
}
