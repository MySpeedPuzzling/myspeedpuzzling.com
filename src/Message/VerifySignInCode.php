<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;
use SensitiveParameter;

/**
 * A 6-digit code typed on the "Check your email" screen, for the sign-in this
 * browser asked for (SignInCodePending). Dispatched by SignInCodeAuthenticator;
 * the handler returns a SignInCodeCheck.
 */
final readonly class VerifySignInCode
{
    public function __construct(
        public UuidInterface $requestId,
        /** Already normalised to six digits (SignInCodeHasher::normalize()) */
        #[SensitiveParameter]
        public string $code,
    ) {
    }
}
