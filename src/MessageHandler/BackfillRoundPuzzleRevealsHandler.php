<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Message\BackfillRoundPuzzleReveals;
use SpeedPuzzling\Web\Services\PuzzleImageNamer;
use SpeedPuzzling\Web\Services\SecretPuzzleHides;
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
        private Filesystem $filesystem,
        private PuzzleImageNamer $puzzleImageNamer,
    ) {
    }

    /**
     * @return array{changes: list<string>, unmatched: list<string>}
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

        if ($message->dryRun === false) {
            $this->secretPuzzleHides->resync(...array_values($matchedPuzzles));

            // A secret puzzle created before random image names has a guessable one (brand-name-pieces-idprefix) - with
            // "hide image only" its name and id are public. Move the image to a random name while it is still hidden.
            foreach ($matchedPuzzles as $puzzle) {
                $moved = $this->moveGuessableImage($puzzle, $now);

                if ($moved !== null) {
                    $movedImages[] = $moved;
                }
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
        ];
    }

    /**
     * Copies the object to a random name, points the puzzle at it and deletes the old one. The images cache (nginx in
     * front of imgproxy) may still hold thumbnails requested under the old name - see docs (no purge endpoint).
     */
    private function moveGuessableImage(Puzzle $puzzle, DateTimeImmutable $now): null|string
    {
        $oldPath = $puzzle->image;

        if ($oldPath === null || $puzzle->isImageHiddenAt($now) === false) {
            return null;
        }

        $extension = pathinfo($oldPath, PATHINFO_EXTENSION);
        $newPath = $this->puzzleImageNamer->secretFilename($extension !== '' ? $extension : 'jpg');

        if ($this->filesystem->fileExists($oldPath) === false) {
            return sprintf('image of puzzle %s: %s is missing in storage - left as it is', $puzzle->id->toString(), $oldPath);
        }

        $this->filesystem->copy($oldPath, $newPath);
        $puzzle->moveImageTo($newPath);
        $this->filesystem->delete($oldPath);

        return sprintf('image of puzzle %s moved: %s -> %s', $puzzle->id->toString(), $oldPath, $newPath);
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
