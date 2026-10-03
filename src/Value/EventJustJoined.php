<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * "You just clicked I'm going": the join flow (F2 in docs/features/marketplace/11-events.md) adds a flash of this
 * type with the competition id as its value, the event and edition pages read it to show the marketplace card in
 * buyer wording, highlighted. base.html.twig renders only success/danger/warning/info flashes, so this one never
 * shows up as a message on its own.
 */
final class EventJustJoined
{
    public const string FLASH = 'event_just_joined';
}
