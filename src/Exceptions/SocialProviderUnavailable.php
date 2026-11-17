<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\OauthProvider;

/**
 * The provider's token endpoint failed on its side (`server_error`,
 * `temporarily_unavailable`, a 5xx or no answer at all) - twice, since
 * SocialProfileFetcher retries once. Nothing the visitor or our config did:
 * the sign-in pages say "temporary problem at %provider%, try again in a few
 * minutes" instead of the generic failure.
 */
final class SocialProviderUnavailable extends \RuntimeException
{
    public function __construct(
        public readonly OauthProvider $provider,
        public readonly string $providerError,
        \Throwable $previous,
    ) {
        parent::__construct(
            sprintf('%s token endpoint is temporarily unavailable (%s).', $provider->displayName(), $providerError),
            0,
            $previous,
        );
    }
}
