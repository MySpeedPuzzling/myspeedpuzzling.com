<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The kinds of an event, edition or series (docs/features/events-page/event-types.md) - optional: null where none is
 * set (every event before the field existed), and an edition without its own shows its series'. Never empty, each kind
 * once, in EventType's order. Stored as a jsonb list of values, an empty list = not set.
 */
readonly final class EventTypes
{
    /**
     * @param non-empty-list<EventType> $types
     */
    private function __construct(
        public array $types,
    ) {
    }

    /**
     * @param iterable<EventType> $types none = not set (null)
     */
    public static function of(iterable $types): null|self
    {
        $chosen = [];
        foreach ($types as $type) {
            $chosen[$type->value] = true;
        }

        $ordered = array_values(array_filter(
            EventType::cases(),
            static fn (EventType $type): bool => isset($chosen[$type->value]),
        ));

        return $ordered === [] ? null : new self($ordered);
    }

    /**
     * A stored list (the jsonb column, decoded) - null when not set. Unknown values are left out.
     */
    public static function fromStored(mixed $stored): null|self
    {
        if (is_array($stored) === false) {
            return null;
        }

        $types = [];
        foreach ($stored as $value) {
            $type = is_string($value) ? EventType::tryFrom($value) : null;

            if ($type !== null) {
                $types[] = $type;
            }
        }

        return self::of($types);
    }

    /**
     * The jsonb column as a query returns it (a JSON string) - null when not set
     */
    public static function fromJson(mixed $json): null|self
    {
        if (is_string($json) === false || $json === '') {
            return null;
        }

        return self::fromStored(json_decode($json, true));
    }

    /**
     * @return list<string>
     */
    public static function toStoredOrEmpty(null|self $types): array
    {
        return $types?->toStored() ?? [];
    }

    public static function same(null|self $a, null|self $b): bool
    {
        return $a?->types === $b?->types;
    }

    /**
     * What a page shows: nothing when not set or a plain competition (most events are one, as before the field
     * existed), else every kind - a competition with a puzzle swap shows both, so it is not mistaken for a swap only
     *
     * @return list<EventType>
     */
    public static function shownOf(null|self $types): array
    {
        if ($types === null || $types->types === [EventType::Competition]) {
            return [];
        }

        return $types->types;
    }

    /**
     * @return non-empty-list<string>
     */
    public function toStored(): array
    {
        return array_map(static fn (EventType $type): string => $type->value, $this->types);
    }
}
