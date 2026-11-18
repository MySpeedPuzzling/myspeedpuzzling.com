<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;

/**
 * The puzzle choice a competitor sends with their time proposal (competition toolkit v2 C2, design-v2
 * competitors-splits.md §2.2, decision D3): which puzzles of a `pick` round they finished, in solving order.
 *
 * The phone speaks places, never ids - its competitor JSON has no round puzzle id (nothing of a hidden puzzle leaves
 * the server, the label is all it shows): `[{"position": 2, "label": "B"}, …]`. The server resolves the places under
 * the event's lock against the round's puzzles in the organiser's order (RoundPuzzleLabel::sqlOrder() / sort()): place
 * p is the p-th puzzle, and its label (RoundPuzzleLabel::effective()) must be the one sent - a reorder or relabel since
 * the phone's state is `puzzles_changed`, never a wrong puzzle. Secret puzzles are chosen by label like the judge does
 * before the reveal.
 *
 * Stored on the proposal (competition_time_proposal.puzzle_choice) as round puzzle ids, lower-case, solving order. The
 * choice is the competitor's word for the judge to check - partial is fine (the judge completes it), it never decides
 * anything on its own and never costs the tap: a refusal (time_proposal.reason.*) drops the choice, never the time.
 */
final class ProposalPuzzleChoice
{
    public const string NOT_USED = 'puzzles_not_used';
    public const string UNKNOWN_PUZZLE = 'unknown_round_puzzle';
    public const string CHANGED = 'puzzles_changed';
    public const string LISTED_TWICE = 'puzzle_listed_twice';
    public const string TOO_MANY = 'too_many_puzzles';
    public const string INVALID = 'invalid_puzzles';

    public const int MAX_POSITION = 9999;

    /**
     * Shape only - which places the round has is resolve()'s business. Null and an empty list are no choice.
     *
     * @return null|non-empty-list<array{position: int, label: string}>
     * @throws \InvalidArgumentException
     */
    public static function fromWire(mixed $value): null|array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Puzzles are null or a list.');
        }

        if (count($value) > RoundEntryPuzzles::MAX_ITEMS) {
            throw new \InvalidArgumentException(sprintf('At most %d puzzles.', RoundEntryPuzzles::MAX_ITEMS));
        }

        $items = [];

        foreach ($value as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['position', 'label']) !== []) {
                throw new \InvalidArgumentException('A puzzle is {"position", "label"}.');
            }

            $position = $item['position'] ?? null;
            $label = $item['label'] ?? null;

            if (!is_int($position) || $position < 1 || $position > self::MAX_POSITION) {
                throw new \InvalidArgumentException(sprintf('A position is 1..%d.', self::MAX_POSITION));
            }

            if (!is_string($label) || $label === '' || mb_strlen($label) > RoundPuzzleLabel::MAX_LENGTH) {
                throw new \InvalidArgumentException(sprintf('A label is 1..%d characters.', RoundPuzzleLabel::MAX_LENGTH));
            }

            $items[] = ['position' => $position, 'label' => $label];
        }

        return $items === [] ? null : $items;
    }

    /**
     * The places sent → round puzzle ids in solving order, or the refusal (time_proposal.reason.*).
     *
     * @param non-empty-list<array{position: int, label: string}> $wire
     * @param list<array{id: string, label: string}> $slots the round's puzzles in the organiser's order, effective labels
     * @return list<string>|string
     */
    public static function resolve(array $wire, array $slots, RoundPuzzleFormat $format, null|int $toSolve): array|string
    {
        if ($format !== RoundPuzzleFormat::Pick || $toSolve === null) {
            return self::NOT_USED;
        }

        $ids = [];

        foreach ($wire as $item) {
            $slot = $slots[$item['position'] - 1] ?? null;

            if ($slot === null) {
                return self::UNKNOWN_PUZZLE;
            }

            if ($slot['label'] !== $item['label']) {
                return self::CHANGED;
            }

            $id = strtolower($slot['id']);

            if (in_array($id, $ids, true)) {
                return self::LISTED_TWICE;
            }

            $ids[] = $id;
        }

        if (count($ids) > $toSolve) {
            return self::TOO_MANY;
        }

        return $ids;
    }

    /**
     * resolve() against a round entity (the write side): the format first - a round that takes no choice reads none of
     * its puzzles - then the round's puzzles in the organiser's order (one statement: the round's puzzle collection).
     *
     * @param non-empty-list<array{position: int, label: string}> $wire
     * @return list<string>|string
     */
    public static function resolveForRound(array $wire, CompetitionRound $round): array|string
    {
        $toSolve = $round->puzzlesToSolveCount();

        if ($toSolve === null) {
            return self::NOT_USED;
        }

        $puzzles = RoundPuzzleLabel::sort(
            array_values($round->roundPuzzles->toArray()),
            static fn (CompetitionRoundPuzzle $puzzle): array => [$puzzle->position, $puzzle->id->toString()],
        );
        $slots = [];

        foreach ($puzzles as $index => $puzzle) {
            $slots[] = ['id' => $puzzle->id->toString(), 'label' => RoundPuzzleLabel::effective($puzzle->label, $index + 1)];
        }

        return self::resolve($wire, $slots, $round->puzzleFormat, $toSolve);
    }

    /**
     * What `puzzle_choice` holds - a list of round puzzle ids, null = none.
     *
     * @return null|list<string>
     */
    public static function fromStored(mixed $value): null|array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        $ids = [];

        foreach ($value as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The stored choice against the round's puzzles as they are now (the read side) - a puzzle removed from the round
     * since simply drops out. `position` / `label` are what the competitor's phone shows (the label of a hidden puzzle
     * is all it ever learns); `id` is for the judges only.
     *
     * @param list<string> $choice
     * @param list<array{id: string, label: null|string}> $roundPuzzles in the organiser's order, stored labels
     * @return list<array{id: string, position: int, label: string}>
     */
    public static function places(array $choice, array $roundPuzzles): array
    {
        $placeById = [];

        foreach ($roundPuzzles as $index => $roundPuzzle) {
            $placeById[strtolower($roundPuzzle['id'])] = [
                'id' => strtolower($roundPuzzle['id']),
                'position' => $index + 1,
                'label' => RoundPuzzleLabel::effective($roundPuzzle['label'], $index + 1),
            ];
        }

        $places = [];

        foreach ($choice as $id) {
            $place = $placeById[strtolower($id)] ?? null;

            if ($place !== null) {
                $places[] = $place;
            }
        }

        return $places;
    }
}
