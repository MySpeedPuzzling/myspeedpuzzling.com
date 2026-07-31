<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A verified provider profile parked in cache between the OAuth callback and
 * the rule-4 interstitial confirmation - the one moment the duplicate-account
 * mistake can still be prevented (plan §Linking vs merging).
 */
final readonly class ParkedSocialRegistration
{
    public function __construct(
        public SocialUserProfile $profile,
        public null|string $locale,
    ) {
    }
}
