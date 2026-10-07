<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

/**
 * "What would this reveal earlier than planned?" - asked before a change that may let secret puzzles out (removing a
 * puzzle from its round, deleting a round, a round's automatic reveal moved earlier by its start or its reveal delay),
 * so the organiser confirms exactly that list (hash()) and the flash can say what happened. Read-only.
 *
 * One item per puzzle:
 * - revealsAt: when it comes out - null = right away (the moment is over already, or the puzzle leaves the round)
 * - previousRevealsAt: the moment it moves from - the row's reveal as it is now (a round change: the round's current
 *   automatic reveal); null for a removal or a deleted round (the puzzle leaves the round, nothing moves)
 * - scope: how far it comes out then - SCOPE_EVERYWHERE (everything this round hid, on the whole site),
 *   SCOPE_NAME_EVERYWHERE (its name on the whole site, its picture stays hidden elsewhere until hiddenElsewhereUntil) or
 *   SCOPE_EVENT (on this event only: elsewhere something else keeps it hidden until hiddenElsewhereUntil, or - null -
 *   it was public elsewhere all along: a public catalogue puzzle the round keeps secret on its event pages only,
 *   CompetitionRoundPuzzle::$hidesEverywhere false)
 * - everywhere: scope === SCOPE_EVERYWHERE (kept for the internal API's `revealedEverywhere`)
 *
 * @phpstan-type RevealedPuzzle array{
 *     id: string,
 *     name: string,
 *     revealsAt: null|DateTimeImmutable,
 *     previousRevealsAt: null|DateTimeImmutable,
 *     scope: 'everywhere'|'name_everywhere'|'event',
 *     everywhere: bool,
 *     hiddenElsewhereUntil: null|DateTimeImmutable,
 * }
 */
readonly final class SecretRevealPreview
{
    public const string SCOPE_EVERYWHERE = 'everywhere';
    public const string SCOPE_NAME_EVERYWHERE = 'name_everywhere';
    public const string SCOPE_EVENT = 'event';

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
     * @return list<RevealedPuzzle>
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

            if ($imageComesOut) {
                $revealed[$puzzle->id->toString()] = self::item($puzzle, null, null, self::SCOPE_EVERYWHERE, null);
            } elseif ($nameComesOut) {
                $revealed[$puzzle->id->toString()] = self::item($puzzle, null, null, self::SCOPE_NAME_EVERYWHERE, $after['imageHiddenUntil']);
            }
        }

        return array_values($revealed);
    }

    /**
     * A new start and/or reveal delay for the round: its secret puzzles whose automatic reveal (start + delay) would come
     * EARLIER than now planned - at the new moment, or right away when that is over already. Nothing when the moment
     * stays or moves later (a later reveal never lets anything out early; the handler re-syncs the hide). Scheduled and
     * manual reveals never follow the round, and reveals that already happened are pinned by the handler - neither is
     * listed.
     *
     * Each puzzle is judged at the moment it comes out on this event: its name and its picture separately, against the
     * other rounds that keep it secret on the whole site (SecretPuzzleHides::hideOf()). A row keeping a public catalogue
     * puzzle secret on this event only (not $hidesEverywhere) lets it out on this event only - SCOPE_EVENT, with no
     * until-when elsewhere when nothing else hides it.
     *
     * @return list<RevealedPuzzle>
     */
    public function byChangingRound(CompetitionRound $round, DateTimeImmutable $newStartsAt, int $newRevealDelayMinutes): array
    {
        $now = $this->clock->now();
        $newAutomaticReveal = RoundPuzzleReveal::automaticRevealAt($newStartsAt, $newRevealDelayMinutes);

        if ($newAutomaticReveal >= $round->automaticRevealAt()) {
            return [];
        }

        $rightAway = $newAutomaticReveal <= $now;
        // From this moment on the puzzle is out on this event - what the rest of the site does then decides the scope
        $outFrom = $rightAway ? $now : $newAutomaticReveal;
        $revealed = [];

        foreach ($round->roundPuzzles as $row) {
            if ($row->revealMode !== RoundPuzzleReveal::Automatic || $row->isHiddenAt($now) === false) {
                continue;
            }

            $puzzle = $row->puzzle;
            // Where it moves from: the round's automatic reveal as it is now - the yes is for this move, not for another
            // one ending at the same moment (the round changed meanwhile: asked again)
            $previousRevealsAt = $row->revealsAt();
            $revealsAt = $rightAway ? null : $newAutomaticReveal;
            $after = $this->secretPuzzleHides->hideOf($puzzle, [], [$round->id->toString() => $newAutomaticReveal]);

            // No row keeps it hidden on the whole site: its dates stay as they are (SecretPuzzleHides::resync())
            $nameHiddenUntil = $after !== null ? $after['hiddenUntil'] : $puzzle->hideUntil;
            $imageHiddenUntil = $after !== null ? $after['imageHiddenUntil'] : $puzzle->hideImageUntil;
            // The name is hidden elsewhere only while the picture is too (Puzzle::isImageHiddenAt())
            $imageHiddenUntil = self::later($imageHiddenUntil, $nameHiddenUntil);

            $nameHiddenElsewhere = $nameHiddenUntil !== null && $nameHiddenUntil > $outFrom;
            $imageHiddenElsewhere = $imageHiddenUntil !== null && $imageHiddenUntil > $outFrom;
            $rowHidesName = ($row->hideMode ?? PuzzleHideMode::Entirely) === PuzzleHideMode::Entirely;

            if ($row->hidesEverywhere === false) {
                // The row keeps the puzzle secret on this event's pages only - the rest of the site never followed it:
                // there the puzzle is public all along (null), or something else hides it until then
                $item = self::item(
                    $puzzle,
                    $revealsAt,
                    $previousRevealsAt,
                    self::SCOPE_EVENT,
                    match (true) {
                        $rowHidesName && $nameHiddenElsewhere => $nameHiddenUntil,
                        $imageHiddenElsewhere => $imageHiddenUntil,
                        default => null,
                    },
                );
            } elseif ($rowHidesName && $nameHiddenElsewhere) {
                // Nothing of it comes out elsewhere before the name does
                $item = self::item($puzzle, $revealsAt, $previousRevealsAt, self::SCOPE_EVENT, $nameHiddenUntil);
            } elseif ($imageHiddenElsewhere) {
                // The row hides the name too: it comes out everywhere, the picture stays hidden elsewhere. An image-only
                // row: only its picture comes out, on this event
                $item = self::item(
                    $puzzle,
                    $revealsAt,
                    $previousRevealsAt,
                    $rowHidesName ? self::SCOPE_NAME_EVERYWHERE : self::SCOPE_EVENT,
                    $imageHiddenUntil,
                );
            } else {
                $item = self::item($puzzle, $revealsAt, $previousRevealsAt, self::SCOPE_EVERYWHERE, null);
            }

            $revealed[] = $item;
        }

        return $revealed;
    }

    /**
     * After the handler's locks: a change that reveals something nobody said yes to - a refusal without a yes (the
     * internal API without "confirmReveal", any caller that did not ask), or a confirmation for another list than the
     * one now (hash).
     *
     * Nothing to reveal any more (the list became empty meanwhile - e.g. another change already moved the reveal) is
     * never refused: the yes is not needed, and going ahead lets nothing out earlier.
     *
     * @param list<RevealedPuzzle> $revealed
     */
    public static function refuses(array $revealed, bool $refuseAny, null|string $confirmedHash): bool
    {
        if ($revealed === []) {
            return false;
        }

        if ($refuseAny) {
            return true;
        }

        return $confirmedHash !== null && self::hash($revealed) !== $confirmedHash;
    }

    /**
     * What the organiser confirmed - a confirmation counts only for exactly the list it was shown: the same puzzles,
     * each revealed as far as it said (scope, until when elsewhere), at the same moment (or right away) and moving from
     * the same moment (a round whose reveal changed meanwhile - e.g. a longer delay set elsewhere - asks again, even
     * when the new moment is the same: the yes was for a smaller move).
     *
     * @param list<RevealedPuzzle> $revealed
     */
    public static function hash(array $revealed): string
    {
        $items = array_map(static fn (array $item): string => implode('|', [
            $item['id'],
            $item['scope'],
            $item['hiddenElsewhereUntil'] !== null ? (string) $item['hiddenElsewhereUntil']->getTimestamp() : '',
            $item['revealsAt'] !== null ? (string) $item['revealsAt']->getTimestamp() : 'now',
            $item['previousRevealsAt'] !== null ? (string) $item['previousRevealsAt']->getTimestamp() : '',
        ]), $revealed);
        sort($items);

        return hash('sha256', implode(',', $items));
    }

    /**
     * @param 'everywhere'|'name_everywhere'|'event' $scope
     * @return RevealedPuzzle
     */
    private static function item(
        Puzzle $puzzle,
        null|DateTimeImmutable $revealsAt,
        null|DateTimeImmutable $previousRevealsAt,
        string $scope,
        null|DateTimeImmutable $hiddenElsewhereUntil,
    ): array {
        return [
            'id' => $puzzle->id->toString(),
            'name' => $puzzle->name,
            'revealsAt' => $revealsAt,
            'previousRevealsAt' => $previousRevealsAt,
            'scope' => $scope,
            'everywhere' => $scope === self::SCOPE_EVERYWHERE,
            'hiddenElsewhereUntil' => $scope === self::SCOPE_EVERYWHERE ? null : $hiddenElsewhereUntil,
        ];
    }

    private static function later(null|DateTimeImmutable $a, null|DateTimeImmutable $b): null|DateTimeImmutable
    {
        if ($a === null) {
            return $b;
        }

        return $b !== null && $b > $a ? $b : $a;
    }
}
