<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Not a failure in the user's eyes: rule 4 matched nothing, so instead of
 * silently creating an account the callback parks the provider profile and
 * sends the visitor to the interstitial confirmation page (plan §Linking vs
 * merging - the only moment the duplicate-account mistake can be prevented).
 * Thrown from the account resolver, translated into a redirect by the
 * authenticator's onAuthenticationFailure.
 */
final class SocialRegistrationRequired extends AuthenticationException
{
    public function __construct(
        public readonly string $registrationToken,
    ) {
        parent::__construct('No matching account - interstitial confirmation required.');
    }
}
