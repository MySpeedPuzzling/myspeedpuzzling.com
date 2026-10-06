<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Message\BackfillRoundPuzzleReveals;
use SpeedPuzzling\Web\Services\PuzzleImageNamer;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleOwnership;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Round puzzles saved before the reveal was kept on the round puzzle (2026-10) do not say whether the round created
 * their puzzle, so the puzzle's site-wide hide dates do not follow the round's reveal. This marks the rows that did
 * create it - a puzzle created on the fly by AddPuzzleToCompetitionRoundHandler: unapproved, and its id (UUIDv7) made
 * within a couple of minutes of the round puzzle's id - and only while their reveal is still ahead (a revealed puzzle
 * stays as it is). Then their puzzles' dates are re-synced (SecretPuzzleHides). Every other puzzle hidden in the
 * future is listed for a person to look at. Idempotent; a dry run changes nothing.
 */
#[AsMessageHandler]
readonly final class BackfillRoundPuzzleRevealsHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SecretPuzzleHides $secretPuzzleHides,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{changes: list<string>, unmatched: list<string>, eventPageOnly: list<string>, records: list<string>, obsoleteImages: list<string>}
     */
    public function __invoke(BackfillRoundPuzzleReveals $message): array
    {
        $now = $this->clock->now();

        /** @var array<CompetitionRoundPuzzle> $roundPuzzles */
        $roundPuzzles = $this->entityManager->createQueryBuilder()
            ->select('crp', 'r', 'p')
            ->from(CompetitionRoundPuzzle::class, 'crp')
            ->join('crp.round', 'r')
            ->join('crp.puzzle', 'p')
            ->where('crp.hideUntilRoundStarts = true')
            ->andWhere('crp.hidesEverywhere = false')
            ->orderBy('r.startsAt')
            ->getQuery()
            ->getResult();

        $changes = [];
        /** @var array<string, Puzzle> $matchedPuzzles */
        $matchedPuzzles = [];

        foreach ($roundPuzzles as $roundPuzzle) {
            $puzzle = $roundPuzzle->puzzle;
            $revealsAt = $roundPuzzle->revealsAt();

            if ($revealsAt !== null && $revealsAt <= $now) {
                continue;
            }

            if ($puzzle->approved || self::createdTogether($puzzle->id, $roundPuzzle->id) === false) {
                continue;
            }

            $before = sprintf('hide_until %s, hide_image_until %s', self::format($puzzle->hideUntil), self::format($puzzle->hideImageUntil));
            $changes[$roundPuzzle->id->toString()] = [$roundPuzzle, $before];
            $matchedPuzzles[$puzzle->id->toString()] = $puzzle;

            if ($message->dryRun === false) {
                $roundPuzzle->keepHiddenEverywhere();
            }
        }

        // The same secret puzzle used later in another round (added as an existing puzzle): that round keeps it secret
        // too - its reveal counts for the puzzle's site-wide hide like the creating one's
        foreach ($roundPuzzles as $roundPuzzle) {
            $revealsAt = $roundPuzzle->revealsAt();

            if (
                isset($changes[$roundPuzzle->id->toString()])
                || isset($matchedPuzzles[$roundPuzzle->puzzle->id->toString()]) === false
                || ($revealsAt !== null && $revealsAt <= $now)
            ) {
                continue;
            }

            $puzzle = $roundPuzzle->puzzle;
            $before = sprintf('also: hide_until %s, hide_image_until %s', self::format($puzzle->hideUntil), self::format($puzzle->hideImageUntil));
            $changes[$roundPuzzle->id->toString()] = [$roundPuzzle, $before];

            if ($message->dryRun === false) {
                $roundPuzzle->keepHiddenEverywhere();
            }
        }

        $movedImages = [];
        $obsoleteImages = [];
        $imagesBefore = array_map(static fn (Puzzle $puzzle): null|string => $puzzle->image, $matchedPuzzles);

        if ($message->dryRun === false) {
            // Re-syncing also moves a guessable picture name (brand-name-pieces-idprefix - with "hide image only" its
            // name and id are public) to a random one; the old object goes after the commit (SecretPuzzleHides)
            $this->secretPuzzleHides->resync(...array_values($matchedPuzzles));
        }

        foreach ($matchedPuzzles as $puzzleId => $puzzle) {
            $oldPath = $imagesBefore[$puzzleId];

            if ($oldPath === null || PuzzleImageNamer::isSecretFilename($oldPath)) {
                continue;
            }

            if ($message->dryRun) {
                $movedImages[] = sprintf('image of puzzle %s would move to a random name: %s', $puzzleId, $oldPath);
                $obsoleteImages[] = $oldPath;
            } elseif ($puzzle->image !== $oldPath && $puzzle->image !== null) {
                $movedImages[] = sprintf('image of puzzle %s moved: %s -> %s (the old object is deleted after the commit)', $puzzleId, $oldPath, $puzzle->image);
                $obsoleteImages[] = $oldPath;
            } else {
                $movedImages[] = sprintf('image of puzzle %s: %s could not be moved (missing in storage?) - left as it is', $puzzleId, $oldPath);
            }
        }

        $lines = [];

        foreach ($changes as [$roundPuzzle, $before]) {
            $revealsAt = $roundPuzzle->revealsAt();
            $lines[] = sprintf(
                'round puzzle %s (puzzle %s "%s", round "%s" starting %s UTC): %s -> reveal %s%s',
                $roundPuzzle->id->toString(),
                $roundPuzzle->puzzle->id->toString(),
                $roundPuzzle->puzzle->name,
                $roundPuzzle->round->name,
                $roundPuzzle->round->startsAt->format('Y-m-d H:i'),
                $before,
                $revealsAt === null ? 'manual' : $revealsAt->format('Y-m-d H:i') . ' UTC',
                $message->dryRun ? '' : sprintf(
                    ' (now hide_until %s, hide_image_until %s)',
                    self::format($roundPuzzle->puzzle->hideUntil),
                    self::format($roundPuzzle->puzzle->hideImageUntil),
                ),
            );
        }

        return [
            'changes' => [...$lines, ...$movedImages],
            'unmatched' => $this->unmatchedHiddenPuzzles($now, array_keys($matchedPuzzles)),
            'eventPageOnly' => $this->eventPageOnlySecrets($roundPuzzles, $changes, $now),
            'records' => $this->existingRecords(array_keys($matchedPuzzles)),
            'obsoleteImages' => $obsoleteImages,
        ];
    }

    /**
     * Round puzzles still secret on their event page only that this backfill leaves so - the picture of an "image only"
     * one is public everywhere else (and its file name may be guessable), so a person checks they are catalogue puzzles.
     *
     * @param array<CompetitionRoundPuzzle> $roundPuzzles
     * @param array<string, array{CompetitionRoundPuzzle, string}> $changes
     * @return list<string>
     */
    private function eventPageOnlySecrets(array $roundPuzzles, array $changes, DateTimeImmutable $now): array
    {
        $lines = [];

        foreach ($roundPuzzles as $roundPuzzle) {
            if (isset($changes[$roundPuzzle->id->toString()]) || $roundPuzzle->isHiddenAt($now) === false) {
                continue;
            }

            $puzzle = $roundPuzzle->puzzle;
            $lines[] = sprintf(
                'round puzzle %s (puzzle %s "%s", %s, round "%s"): %s on the event page only, image %s',
                $roundPuzzle->id->toString(),
                $puzzle->id->toString(),
                $puzzle->name,
                $puzzle->approved ? 'approved' : 'unapproved',
                $roundPuzzle->round->name,
                ($roundPuzzle->hideMode ?? PuzzleHideMode::Entirely) === PuzzleHideMode::ImageOnly ? 'IMAGE ONLY' : 'entirely',
                $puzzle->image ?? 'none',
            );
        }

        return $lines;
    }

    /**
     * What already exists on the puzzles this backfill hides on the whole site - their owners would no longer see the
     * puzzle until the reveal; look before --write.
     *
     * @param array<string> $puzzleIds
     * @return list<string>
     */
    private function existingRecords(array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        /** @var list<array{id: string, name: string, times: int|string, collections: int|string, wishlists: int|string, listings: int|string, loans: int|string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            <<<SQL
SELECT
    p.id,
    p.name,
    (SELECT COUNT(*) FROM puzzle_solving_time t WHERE t.puzzle_id = p.id) AS times,
    (SELECT COUNT(*) FROM collection_item ci WHERE ci.puzzle_id = p.id) AS collections,
    (SELECT COUNT(*) FROM wish_list_item wli WHERE wli.puzzle_id = p.id) AS wishlists,
    (SELECT COUNT(*) FROM sell_swap_list_item ssli WHERE ssli.puzzle_id = p.id) AS listings,
    (SELECT COUNT(*) FROM lent_puzzle lp WHERE lp.puzzle_id = p.id) AS loans
FROM puzzle p
WHERE p.id IN (:ids)
ORDER BY p.name
SQL,
            ['ids' => array_values($puzzleIds)],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): string => sprintf(
            'puzzle %s "%s": %d times, %d collection items, %d wishlist items, %d sell/swap listings, %d loans',
            $row['id'],
            $row['name'],
            (int) $row['times'],
            (int) $row['collections'],
            (int) $row['wishlists'],
            (int) $row['listings'],
            (int) $row['loans'],
        ), $rows);
    }

    /**
     * Every other puzzle hidden in the future - placeholders, hand-made embargoes, rows this backfill could not tell
     * apart - for a person to review.
     *
     * @param array<string> $matchedPuzzleIds
     * @return list<string>
     */
    private function unmatchedHiddenPuzzles(DateTimeImmutable $now, array $matchedPuzzleIds): array
    {
        /** @var array<Puzzle> $puzzles */
        $puzzles = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Puzzle::class, 'p')
            ->where('p.hideUntil > :now OR p.hideImageUntil > :now')
            ->setParameter('now', $now)
            ->orderBy('p.name')
            ->getQuery()
            ->getResult();

        $lines = [];

        foreach ($puzzles as $puzzle) {
            if (in_array($puzzle->id->toString(), $matchedPuzzleIds, true)) {
                continue;
            }

            $lines[] = sprintf(
                'puzzle %s "%s" (%s): hide_until %s, hide_image_until %s',
                $puzzle->id->toString(),
                $puzzle->name,
                $puzzle->approved ? 'approved' : 'unapproved',
                self::format($puzzle->hideUntil),
                self::format($puzzle->hideImageUntil),
            );
        }

        return $lines;
    }

    /**
     * The handler creating a puzzle for a round made both ids (UUIDv7, millisecond timestamps) within one request.
     */
    private static function createdTogether(UuidInterface $puzzleId, UuidInterface $roundPuzzleId): bool
    {
        $puzzleCreatedAt = self::uuidV7Milliseconds($puzzleId);
        $roundPuzzleCreatedAt = self::uuidV7Milliseconds($roundPuzzleId);

        if ($puzzleCreatedAt === null || $roundPuzzleCreatedAt === null) {
            return false;
        }

        return abs($puzzleCreatedAt - $roundPuzzleCreatedAt) <= RoundPuzzleOwnership::CREATED_TOGETHER_SECONDS * 1000;
    }

    /**
     * A UUIDv7 starts with its creation time: 48 bits of Unix milliseconds. Null for any other version.
     */
    private static function uuidV7Milliseconds(UuidInterface $uuid): null|int
    {
        $hex = str_replace('-', '', $uuid->toString());

        if ($hex[12] !== '7') {
            return null;
        }

        return (int) hexdec(substr($hex, 0, 12));
    }

    private static function format(null|DateTimeImmutable $moment): string
    {
        return $moment === null ? 'none' : $moment->format('Y-m-d H:i');
    }
}
