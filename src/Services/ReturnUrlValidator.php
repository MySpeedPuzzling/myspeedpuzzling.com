<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Symfony\Component\HttpFoundation\Request;

final class ReturnUrlValidator
{
    public function sanitize(string $candidate, Request $request): null|string
    {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return null;
        }

        // Protocol-relative URLs (//evil.example/x) would be treated as absolute by browsers.
        if (str_starts_with($candidate, '//')) {
            return null;
        }

        // Allow same-origin relative paths — but reject anything a browser may normalize
        // to a protocol-relative URL, e.g. "/\evil.example/x".
        if (str_starts_with($candidate, '/')) {
            if (isset($candidate[1]) && ($candidate[1] === '/' || $candidate[1] === '\\')) {
                return null;
            }

            return $candidate;
        }

        $parsedHost = parse_url($candidate, PHP_URL_HOST);

        if ($parsedHost === false || $parsedHost === null) {
            return null;
        }

        if (strcasecmp($parsedHost, $request->getHost()) !== 0) {
            return null;
        }

        // Block schemes like javascript: that parse_url still extracts a host from.
        $scheme = parse_url($candidate, PHP_URL_SCHEME);

        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $candidate;
    }
}
