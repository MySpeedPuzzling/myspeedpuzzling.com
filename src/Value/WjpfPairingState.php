<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The opaque CSRF token WJPF sends into the manual pairing flow and expects echoed back.
 *
 * We never interpret it - it is theirs - but we do put it back into a URL we build, so it is
 * held to a strict allowlist rather than sanitised. Anything carrying URL metacharacters or
 * CRLF is dropped entirely; a dropped state fails closed on their side, which is the safe
 * outcome.
 */
final readonly class WjpfPairingState
{
    private const int MAX_LENGTH = 128;

    private function __construct(
        public string $value,
    ) {
    }

    public static function tryFrom(null|string $value): null|self
    {
        if ($value === null) {
            return null;
        }

        $candidate = trim($value);

        if ($candidate === '' || strlen($candidate) > self::MAX_LENGTH) {
            return null;
        }

        // RFC 3986 unreserved characters only - safe in a query string as-is, and with no
        // way to smuggle a separator, a fragment or a header break.
        if (preg_match('/^[A-Za-z0-9._~-]+$/', $candidate) !== 1) {
            return null;
        }

        return new self($candidate);
    }
}
