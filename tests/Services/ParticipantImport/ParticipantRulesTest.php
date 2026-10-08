<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\ParticipantImport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantRules;

/**
 * The rules the participant import and the participants sheet share (ParticipantRules).
 */
final class ParticipantRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{list<int>, int, null|int}>
     */
    public static function teamSizes(): iterable
    {
        yield 'no teams' => [[], 2, null];
        yield 'empty teams only' => [[0, 0], 2, null];
        yield 'the most common' => [[4, 4, 3, 0], 4, 4];
        yield 'a tie - the smaller' => [[3, 4], 3, 3];
        yield 'mostly one person' => [[1, 1, 2], 2, null];
        yield 'above what a round may expect' => [[25, 25, 4], 25, 20];
    }

    /**
     * @param list<int> $sizes
     */
    #[DataProvider('teamSizes')]
    public function testTheUsualSizeAndTheRoundFormsGuess(array $sizes, int $usual, null|int $guessed): void
    {
        // The import's usual size (D16 c) is unchanged; the round form's guess stays within 2..20 (review A-r13)
        self::assertSame($usual, ParticipantRules::usualTeamSize($sizes));
        self::assertSame($guessed, ParticipantRules::guessedTeamSize($sizes));
    }

    public function testAnExternalIdIsOneParticipants(): void
    {
        $people = [
            'a' => ['externalId' => 'R-1', 'deleted' => false],
            'b' => ['externalId' => null, 'deleted' => false],
            'c' => ['externalId' => 'R-3', 'deleted' => true],
        ];

        self::assertNull(ParticipantRules::externalIdTakenBy('R-1', 'a', $people));
        self::assertSame('a', ParticipantRules::externalIdTakenBy('R-1', 'b', $people));
        // Removed people keep theirs - a restore brings them back with it
        self::assertSame('c', ParticipantRules::externalIdTakenBy('R-3', 'b', $people));
        // Exactly equal - the organiser's own numbering
        self::assertNull(ParticipantRules::externalIdTakenBy('r-1', 'b', $people));
        self::assertNull(ParticipantRules::externalIdTakenBy('R-2', 'b', $people));
    }
}
