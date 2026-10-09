<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\EventUrlPath;

/**
 * The key of event_url_redirect (docs/features/organizations/README.md, D6): '' = not part of the old path.
 */
final class EventUrlPathTest extends TestCase
{
    public function testEveryKindOfOldPath(): void
    {
        self::assertSame(['', 'riverbend-spring-open', ''], self::parts(EventUrlPath::event('riverbend-spring-open')));
        self::assertSame(['lantern-brewing-puzzle-night', '', ''], self::parts(EventUrlPath::series('lantern-brewing-puzzle-night')));
        self::assertSame(['lantern-brewing-puzzle-night', 'lantern-night-one', ''], self::parts(EventUrlPath::edition('lantern-brewing-puzzle-night', 'lantern-night-one')));
        self::assertSame(['', 'riverbend-spring-open', 'final'], self::parts(EventUrlPath::eventRound('riverbend-spring-open', 'final')));
        self::assertSame(['lantern-brewing-puzzle-night', 'lantern-night-one', 'main-round'], self::parts(EventUrlPath::editionRound('lantern-brewing-puzzle-night', 'lantern-night-one', 'main-round')));
    }

    public function testEquality(): void
    {
        self::assertTrue(EventUrlPath::edition('series', 'edition')->equals(new EventUrlPath('series', 'edition', '')));
        self::assertFalse(EventUrlPath::edition('series', 'edition')->equals(EventUrlPath::editionRound('series', 'edition', 'round')));
        // An event's path is never a series' path with the same word
        self::assertFalse(EventUrlPath::event('riverbend')->equals(EventUrlPath::series('riverbend')));
    }

    /**
     * @return array{string, string, string}
     */
    private static function parts(EventUrlPath $path): array
    {
        return [$path->seriesSlug, $path->competitionSlug, $path->roundSlug];
    }
}
