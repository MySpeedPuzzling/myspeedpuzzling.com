<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

/**
 * "What would this reveal right away?" - asked before a change that may let secret puzzles out (removing a puzzle from
 * its round, deleting a round, moving a round's start into the past), so the organiser confirms exactly that list
 * (hash()) and the flash can say what happened. Read-only.
 */
readonly final class SecretRevealPreview
{
    public function __construct(
        private SecretPuzzleHides $secretPuzzleHides,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Removing these rows (a removal, a deleted round): the puzzles the whole site would show at once - the remaining
     * rounds decide, and with none left the dates stay (nothing comes out).
     *
     * @param list<CompetitionRoundPuzzle> $roundPuzzles
     * @return list<array{id: string, name: string, everywhere: bool, hiddenElsewhereUntil: null|DateTimeImmutable}>
     */
    public function byRemoving(array $roundPuzzles): array
    {
        $now = $this->clock->now();
        $withoutIds = array_map(static fn (CompetitionRoundPuzzle $row): string => $row->id->toString(), $roundPuzzles);
        $revealed = [];

        foreach ($roundPuzzles as $row) {
            $puzzle = $row->puzzle;

            if (isset($revealed[$puzzle->id->toString()]) || $puzzle->isImageHiddenAt($now) === false) {
                continue;
            }

            $after = $this->secretPuzzleHides->hideOf($puzzle, $withoutIds);

            if ($after === null) {
                continue;
            }

            $nameComesOut = $puzzle->isHiddenAt($now) && ($after['hiddenUntil'] === null || $after['hiddenUntil'] <= $now);
            $imageComesOut = $after['imageHiddenUntil'] <= $now;

            if ($nameComesOut || $imageComesOut) {
                $revealed[$puzzle->id->toString()] = [
                    'id' => $puzzle->id->toString(),
                    'name' => $puzzle->name,
                    'everywhere' => true,
                    'hiddenElsewhereUntil' => null,
                ];
            }
        }

        return array_values($revealed);
    }

    /**
     * A new start for the round: its secret puzzles whose automatic reveal would be over - out on this event at once,
     * and everywhere unless another round keeps the puzzle hidden ($everywhere false, until when).
     *
     * @return list<array{id: string, name: string, everywhere: bool, hiddenElsewhereUntil: null|DateTimeImmutable}>
     */
    public function byMovingRound(CompetitionRound $round, DateTimeImmutable $newStartsAt): array
    {
        $now = $this->clock->now();
        $newAutomaticReveal = RoundPuzzleReveal::Automatic->revealAt($newStartsAt, null);
        $revealed = [];

        if ($newAutomaticReveal === null || $newAutomaticReveal > $now) {
            return [];
        }

        foreach ($round->roundPuzzles as $row) {
            if ($row->isHiddenAt($now) === false || $row->revealMode !== RoundPuzzleReveal::Automatic) {
                continue;
            }

            $after = $this->secretPuzzleHides->hideOf($row->puzzle, [], [$round->id->toString() => $newStartsAt]);
            $stillHiddenUntil = $after !== null
                ? ($after['imageHiddenUntil'] > $now ? $after['imageHiddenUntil'] : null)
                : ($row->puzzle->isImageHiddenAt($now) ? $row->puzzle->hideImageUntil : null);

            $revealed[] = [
                'id' => $row->puzzle->id->toString(),
                'name' => $row->puzzle->name,
                'everywhere' => $stillHiddenUntil === null,
                'hiddenElsewhereUntil' => $stillHiddenUntil,
            ];
        }

        return $revealed;
    }

    /**
     * What the organiser confirmed - a confirmation counts only for exactly the list it was shown.
     *
     * @param list<array{id: string, name: string, everywhere: bool, hiddenElsewhereUntil: null|DateTimeImmutable}> $revealed
     */
    public static function hash(array $revealed): string
    {
        $ids = array_map(static fn (array $item): string => $item['id'], $revealed);
        sort($ids);

        return hash('sha256', implode(',', $ids));
    }
}
