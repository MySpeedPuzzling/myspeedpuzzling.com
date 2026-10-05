<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Value\CompetitionTeamNames;

final class CompetitionTeamNamesTest extends TestCase
{
    public function testEveryLineIsOneTeam(): void
    {
        $names = CompetitionTeamNames::fromText("Forever Hold Your Piece\r\n\n  Glazer   Gals \nKeddie, Set, Go!\n");

        self::assertSame(['Forever Hold Your Piece', 'Glazer Gals', 'Keddie, Set, Go!'], $names->names);
        self::assertSame([], $names->tooLong());
        self::assertFalse($names->tooMany());
    }

    public function testNameRepeatedInTheListCountsOnce(): void
    {
        $names = CompetitionTeamNames::fromText("Jigsaw Junkies\nOn Edge\njigsaw junkies");

        self::assertSame(['Jigsaw Junkies', 'On Edge'], $names->names);
    }

    public function testNothingTypedMeansNoNames(): void
    {
        self::assertSame([], CompetitionTeamNames::fromText(null)->names);
        self::assertSame([], CompetitionTeamNames::fromText(" \n \n")->names);
    }

    public function testWholeListOnOneLineIsTooLong(): void
    {
        $line = str_repeat('Some Assembly Required ', 12);
        $names = CompetitionTeamNames::fromText("Grandma\n" . $line);

        self::assertGreaterThan(CompetitionTeam::NAME_MAX_LENGTH, mb_strlen($line));
        self::assertSame([trim($line)], $names->tooLong());
    }

    public function testTooManyTeamsAtOnce(): void
    {
        $text = implode("\n", array_map(
            static fn (int $i): string => 'Team ' . $i,
            range(1, CompetitionTeamNames::MAX_TEAMS_AT_ONCE + 1),
        ));

        self::assertTrue(CompetitionTeamNames::fromText($text)->tooMany());
    }
}
