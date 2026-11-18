<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit\TimeProposals;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\ProposalPuzzleChoice;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;

/**
 * The competitor's puzzle choice (competition toolkit v2 C2, design-v2 competitors-splits.md §2.2): places from the
 * phone resolved against the round's puzzles - positions and labels, hidden puzzles by label, partial choices, and
 * every refusal.
 */
final class ProposalPuzzleChoiceTest extends TestCase
{
    // A pick round of three in the organiser's order: A (a secret one - only its letter), the organiser's "Blue box", C
    private const array SLOTS = [
        ['id' => '018d0020-0000-0000-0000-00000000a201', 'label' => 'A'],
        ['id' => '018D0020-0000-0000-0000-00000000A202', 'label' => 'Blue box'],
        ['id' => '018d0020-0000-0000-0000-00000000a203', 'label' => 'C'],
    ];

    public function testPlacesResolveToRoundPuzzleIdsInSolvingOrder(): void
    {
        self::assertSame(
            ['018d0020-0000-0000-0000-00000000a202', '018d0020-0000-0000-0000-00000000a201'],
            ProposalPuzzleChoice::resolve([['position' => 2, 'label' => 'Blue box'], ['position' => 1, 'label' => 'A']], self::SLOTS, RoundPuzzleFormat::Pick, 2),
        );
    }

    public function testAPartialChoiceIsAcceptedTheJudgeCompletesIt(): void
    {
        self::assertSame(
            ['018d0020-0000-0000-0000-00000000a203'],
            ProposalPuzzleChoice::resolve([['position' => 3, 'label' => 'C']], self::SLOTS, RoundPuzzleFormat::Pick, 2),
        );
    }

    /**
     * @return iterable<string, array{non-empty-list<array{position: int, label: string}>, RoundPuzzleFormat, null|int, string}>
     */
    public static function refusals(): iterable
    {
        yield 'a total round takes no choice' => [[['position' => 1, 'label' => 'A']], RoundPuzzleFormat::Total, null, 'puzzles_not_used'];
        yield 'a place the round does not have' => [[['position' => 4, 'label' => 'D']], RoundPuzzleFormat::Pick, 2, 'unknown_round_puzzle'];
        yield 'relabelled since the phone\'s state' => [[['position' => 2, 'label' => 'B']], RoundPuzzleFormat::Pick, 2, 'puzzles_changed'];
        yield 'reordered since the phone\'s state' => [[['position' => 1, 'label' => 'C']], RoundPuzzleFormat::Pick, 2, 'puzzles_changed'];
        yield 'the same puzzle twice' => [[['position' => 1, 'label' => 'A'], ['position' => 1, 'label' => 'A']], RoundPuzzleFormat::Pick, 2, 'puzzle_listed_twice'];
        yield 'more than K' => [[['position' => 1, 'label' => 'A'], ['position' => 2, 'label' => 'Blue box'], ['position' => 3, 'label' => 'C']], RoundPuzzleFormat::Pick, 2, 'too_many_puzzles'];
        yield 'two for a pick of one' => [[['position' => 1, 'label' => 'A'], ['position' => 3, 'label' => 'C']], RoundPuzzleFormat::Pick, 1, 'too_many_puzzles'];
    }

    /**
     * @param non-empty-list<array{position: int, label: string}> $wire
     */
    #[DataProvider('refusals')]
    public function testRefusals(array $wire, RoundPuzzleFormat $format, null|int $toSolve, string $reason): void
    {
        self::assertSame($reason, ProposalPuzzleChoice::resolve($wire, self::SLOTS, $format, $toSolve));
    }

    public function testTheWireShape(): void
    {
        self::assertNull(ProposalPuzzleChoice::fromWire(null));
        self::assertNull(ProposalPuzzleChoice::fromWire([]));
        self::assertSame([['position' => 2, 'label' => 'B']], ProposalPuzzleChoice::fromWire([['label' => 'B', 'position' => 2]]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unreadable(): iterable
    {
        yield 'a string' => ['A'];
        yield 'an object' => [['a' => ['position' => 1, 'label' => 'A']]];
        yield 'an item without a label' => [[['position' => 1]]];
        yield 'an empty label' => [[['position' => 1, 'label' => '']]];
        yield 'a label too long' => [[['position' => 1, 'label' => str_repeat('x', 41)]]];
        yield 'a position as a string' => [[['position' => '1', 'label' => 'A']]];
        yield 'position 0' => [[['position' => 0, 'label' => 'A']]];
        yield 'a position too high' => [[['position' => 10000, 'label' => 'A']]];
        yield 'an id instead of a place' => [[['roundPuzzleId' => '018d0020-0000-0000-0000-00000000a201']]];
        yield 'another key' => [[['position' => 1, 'label' => 'A', 'roundPuzzleId' => 'x']]];
        yield 'too many items' => [array_fill(0, 51, ['position' => 1, 'label' => 'A'])];
    }

    #[DataProvider('unreadable')]
    public function testUnreadableChoices(mixed $wire): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProposalPuzzleChoice::fromWire($wire);
    }

    public function testTheStoredChoiceReadsAsPlacesOfTheRoundNow(): void
    {
        $roundPuzzles = [
            ['id' => '018d0020-0000-0000-0000-00000000a201', 'label' => null],
            ['id' => '018d0020-0000-0000-0000-00000000a202', 'label' => 'Blue box'],
        ];

        self::assertSame(
            [
                ['id' => '018d0020-0000-0000-0000-00000000a202', 'position' => 2, 'label' => 'Blue box'],
                ['id' => '018d0020-0000-0000-0000-00000000a201', 'position' => 1, 'label' => 'A'],
            ],
            // A puzzle removed from the round since (…a203) drops out
            ProposalPuzzleChoice::places(['018d0020-0000-0000-0000-00000000a202', '018d0020-0000-0000-0000-00000000a203', '018d0020-0000-0000-0000-00000000a201'], $roundPuzzles),
        );
        self::assertSame(['a', 'b'], ProposalPuzzleChoice::fromStored('["a", "b"]'));
        self::assertNull(ProposalPuzzleChoice::fromStored(null));
    }
}
