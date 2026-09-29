<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

final readonly class RequestSignInLink
{
    public function __construct(
        public string $email,
        /** Used when the account has no player locale yet (the email must still be readable) */
        public string $fallbackLocale,
        /** The login page's ?return=, validated by the caller; the link lands there */
        public null|string $returnPath = null,
        /**
         * Id of the login_link_request row to book (the requesting browser keeps
         * it to check the 6-digit code against - SignInCodePending). Chosen by
         * the caller because the answer must not depend on whether the address
         * has an account; no row is written for an unknown address.
         */
        public null|UuidInterface $requestId = null,
    ) {
    }
}
