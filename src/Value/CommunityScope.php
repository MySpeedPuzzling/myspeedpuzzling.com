<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The part of the community a page looks at: the whole world or one country (docs/features/players-page/README.md).
 * Carried in the URL as `?scope=cz`; anything that is not a known country code means the world, so a mistyped link
 * still shows a page instead of an error.
 */
readonly final class CommunityScope
{
    public const string WORLD = 'world';

    private function __construct(
        public null|CountryCode $country,
    ) {
    }

    public static function world(): self
    {
        return new self(null);
    }

    public static function country(CountryCode $country): self
    {
        return new self($country);
    }

    public static function fromQuery(mixed $value): self
    {
        if (!is_string($value) || $value === '' || strtolower($value) === self::WORLD) {
            return self::world();
        }

        $country = CountryCode::fromCode($value);

        return $country === null ? self::world() : self::country($country);
    }

    public function isWorld(): bool
    {
        return $this->country === null;
    }

    /**
     * The key of the scope's row in community_scope_stats: 'world' or the lowercase country code.
     */
    public function key(): string
    {
        return $this->country === null ? self::WORLD : $this->country->name;
    }

    /**
     * The `?scope=` value - null for the world, which is the page's default and never in the URL.
     */
    public function queryValue(): null|string
    {
        return $this->country?->name;
    }

    public function equals(self $other): bool
    {
        return $this->key() === $other->key();
    }
}
