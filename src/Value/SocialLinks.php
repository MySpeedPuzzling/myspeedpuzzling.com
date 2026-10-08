<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use InvalidArgumentException;

/**
 * An organization's social links (docs/features/organizations/README.md, P13): at most MAX, http/https only, each URL
 * once (compared case-insensitively), in the order typed. Forms and the internal API validate first
 * (OrganizationFormData) - this only guards the invariant.
 */
readonly final class SocialLinks
{
    public const int MAX = 10;

    /**
     * @param list<string> $urls
     */
    public function __construct(
        public array $urls,
    ) {
        if (count($urls) > self::MAX) {
            throw new InvalidArgumentException(sprintf('At most %d social links.', self::MAX));
        }

        foreach ($urls as $url) {
            if (self::isWebAddress($url) === false) {
                throw new InvalidArgumentException(sprintf('"%s" is not an http(s) address.', $url));
            }
        }
    }

    /**
     * A string is split on line breaks; every URL is trimmed, empty ones and repeats are dropped.
     *
     * @param list<string>|string $input
     */
    public static function fromInput(array|string $input): self
    {
        $lines = is_string($input) ? preg_split('/\R/u', $input) : $input;
        $urls = [];
        $seen = [];

        foreach ($lines === false ? [] : $lines as $line) {
            $url = trim($line);

            if ($url === '') {
                continue;
            }

            $key = mb_strtolower($url);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $urls[] = $url;
        }

        return new self($urls);
    }

    /**
     * @return list<SocialLink>
     */
    public function links(): array
    {
        return array_map(SocialLink::fromUrl(...), $this->urls);
    }

    public static function isWebAddress(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($scheme)
            && in_array(strtolower($scheme), ['http', 'https'], true)
            && is_string($host)
            && $host !== '';
    }
}
