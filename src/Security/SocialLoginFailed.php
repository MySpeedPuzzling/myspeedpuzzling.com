<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Value\OauthProvider;
use SpeedPuzzling\Web\Value\SocialLoginFailureReason;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * Every social sign-in failure. The login page renders the reason's friendly
 * copy (messageKey + %provider%), AuthenticationAuditSubscriber records the
 * reason code in the login_failure audit row - so the visitor never sees
 * Symfony's raw "An authentication exception occurred." and we still see why.
 *
 * Travels through the session to /login, hence the (un)serialize pair.
 */
final class SocialLoginFailed extends CustomUserMessageAuthenticationException
{
    public function __construct(
        public readonly SocialLoginFailureReason $reason,
        OauthProvider $provider,
        null|\Throwable $previous = null,
    ) {
        parent::__construct(
            $reason->messageKey(),
            ['%provider%' => $provider->displayName()],
            0,
            $previous,
        );
    }

    /**
     * @return array<mixed>
     */
    public function __serialize(): array
    {
        return [parent::__serialize(), $this->reason->value];
    }

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): void
    {
        [$parentData, $reason] = $data;
        assert(is_array($parentData) && is_string($reason));

        $this->reason = SocialLoginFailureReason::from($reason);
        parent::__unserialize($parentData);
    }
}
