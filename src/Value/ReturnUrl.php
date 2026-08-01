<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A client-supplied "where to go back to" URL, proven safe to redirect to.
 *
 * Only same-site relative paths survive. Anything that could leave the site is
 * rejected outright rather than sanitised - a redirect target the visitor
 * controls is the classic open-redirect / phishing primitive, and it is worst
 * exactly where it is most useful: right after sign-in, where the victim has
 * just been primed to type a password.
 *
 * Construct with tryFrom(): it returns null for anything suspicious, and every
 * caller falls back to its own sensible default rather than showing an error.
 * Prefer a closed enum of destinations (EditTimeReturnContext) whenever the set
 * of targets is known in advance - not accepting a URL at all beats validating
 * one.
 */
final readonly class ReturnUrl
{
    /** Decoding rounds tolerated before a value is considered hostile. */
    private const int MAX_DECODE_ROUNDS = 5;

    private function __construct(
        public string $path,
    ) {
    }

    public static function tryFrom(null|string $value): null|self
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Validate the raw value and every decoding of it. Percent-encoding is
        // the standard way past a naive prefix check ("%2F%2Fevil.com"), and
        // what the browser finally navigates to is the decoded form.
        $candidate = $value;

        for ($round = 0; $round <= self::MAX_DECODE_ROUNDS; $round++) {
            if (!self::isSameSitePath($candidate)) {
                return null;
            }

            $decoded = rawurldecode($candidate);

            if ($decoded === $candidate) {
                return new self($value);
            }

            $candidate = $decoded;
        }

        // Still decoding after MAX_DECODE_ROUNDS: nothing legitimate is nested
        // that deep, so fail closed rather than keep unwrapping.
        return null;
    }

    public function __toString(): string
    {
        return $this->path;
    }

    private static function isSameSitePath(string $value): bool
    {
        // Header-injection guard: a raw CR/LF (or NUL, or any other control
        // character) has no business in a Location header.
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return false;
        }

        // Browsers normalise backslashes to forward slashes, so "/\evil.com"
        // is treated as scheme-relative and leaves the site. None of the
        // hand-rolled checks this class replaces caught that. Reject the
        // character outright rather than model the normalisation.
        if (str_contains($value, '\\')) {
            return false;
        }

        // Must be an absolute path on this site: exactly one leading slash.
        // Two would be scheme-relative ("//evil.com"); none would be either a
        // full URL with a scheme ("https://evil.com", "javascript:...") or a
        // relative path resolved against whatever page happens to be current.
        return str_starts_with($value, '/') && !str_starts_with($value, '//');
    }
}
