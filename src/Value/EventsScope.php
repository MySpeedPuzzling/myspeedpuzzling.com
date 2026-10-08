<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where the events page looks (docs/features/events-page/README.md, "Scope"): everywhere, online, or one country.
 * Online occurrences count under Online only, never under a country - an online series whose country is Canada is
 * not in the Canada view.
 */
readonly final class EventsScope
{
    private const string EVERYWHERE = 'all';
    private const string ONLINE = 'online';

    private function __construct(
        private string $key,
        private null|CountryCode $country,
    ) {
    }

    public static function everywhere(): self
    {
        return new self(self::EVERYWHERE, null);
    }

    public static function online(): self
    {
        return new self(self::ONLINE, null);
    }

    public static function country(CountryCode $country): self
    {
        return new self($country->name, $country);
    }

    /**
     * `?country=cz` and `?onlineOnly=1` from the URL - onlineOnly wins, an unknown country means everywhere.
     */
    public static function fromQuery(mixed $country, mixed $onlineOnly): self
    {
        if ($onlineOnly === '1' || $onlineOnly === 'true' || $onlineOnly === true || $onlineOnly === 1) {
            return self::online();
        }

        if (is_string($country) && $country !== '') {
            $code = CountryCode::fromCode($country);

            if ($code !== null) {
                return self::country($code);
            }
        }

        return self::everywhere();
    }

    /**
     * The scope key of an occurrence or a series line - what matches() compares (the `sc` of the index, `data-ev-scope`).
     */
    public static function keyOf(bool $isOnline, null|CountryCode $country): string
    {
        if ($isOnline) {
            return self::ONLINE;
        }

        return $country->name ?? '';
    }

    public function matches(bool $isOnline, null|CountryCode $country): bool
    {
        if ($this->key === self::EVERYWHERE) {
            return true;
        }

        if ($this->key === self::ONLINE) {
            return $isOnline;
        }

        return $isOnline === false && $country === $this->country;
    }

    /**
     * `all` / `online` / a lowercase country code
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        if ($this->key === self::ONLINE) {
            return ['onlineOnly' => '1'];
        }

        if ($this->country !== null) {
            return ['country' => $this->country->name];
        }

        return [];
    }

    public function isEverywhere(): bool
    {
        return $this->key === self::EVERYWHERE;
    }

    public function isOnline(): bool
    {
        return $this->key === self::ONLINE;
    }

    public function countryCode(): null|CountryCode
    {
        return $this->country;
    }
}
