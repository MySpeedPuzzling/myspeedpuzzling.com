<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * One rule of NavigationPlaceMap, parsed from its compact notation:
 *   "none"                         no place
 *   "community/events"             a place and its section; a trailing "*" marks a start page ("puzzles/all*")
 *   "puzzles/library*|community"   own|other: the route's playerId is the viewer's (left) or somebody else's (right)
 */
final readonly class PlaceRule
{
    private function __construct(
        public NavPlace $place,
        public null|string $section,
        public bool $isStartPage,
        // Set for own|other rules: where the page of somebody else (or any page seen by a guest) belongs
        public null|self $other,
    ) {
    }

    public static function parse(string $notation): self
    {
        $parts = explode('|', $notation);

        if (count($parts) > 2) {
            throw new \InvalidArgumentException(sprintf('Place rule "%s" has more than one "|"', $notation));
        }

        $own = self::parseSingle($parts[0], $notation);

        if (!isset($parts[1])) {
            return $own;
        }

        return new self($own->place, $own->section, $own->isStartPage, self::parseSingle($parts[1], $notation));
    }

    public function isOwnOrOther(): bool
    {
        return $this->other !== null;
    }

    /**
     * The rule for a page seen by its owner (true) or by anybody else (false).
     */
    public function forOwner(bool $isOwner): self
    {
        if ($this->other === null || $isOwner) {
            return new self($this->place, $this->section, $this->isStartPage, null);
        }

        return $this->other;
    }

    private static function parseSingle(string $part, string $notation): self
    {
        $isStartPage = str_ends_with($part, '*');
        $part = rtrim($part, '*');
        $segments = explode('/', $part, 2);
        $placeValue = $segments[0];
        $section = $segments[1] ?? null;
        $place = NavPlace::tryFrom($placeValue);

        if ($place === null) {
            throw new \InvalidArgumentException(sprintf('Place rule "%s": unknown place "%s"', $notation, $placeValue));
        }

        if ($section !== null && !in_array($section, $place->sections(), true)) {
            throw new \InvalidArgumentException(sprintf('Place rule "%s": "%s" is not a section of %s', $notation, $section, $place->value));
        }

        return new self($place, $section, $isStartPage, null);
    }
}
