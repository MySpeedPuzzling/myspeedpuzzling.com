<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UnlistedPuzzle;
use SpeedPuzzling\Web\Message\SuggestUnlistedPuzzlePairings;
use SpeedPuzzling\Web\Repository\ImportPuzzleDecisionRepository;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\UnlistedPuzzleRepository;
use SpeedPuzzling\Web\Services\Unlisted\UnlistedPuzzleMatcher;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use SpeedPuzzling\Web\Value\ImportPuzzleTier;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The daily look at the grown catalogue (docs/features/data-import/unlisted-puzzles.md "When the catalogue grows").
 * For the unlisted puzzles that are not paired - the longest not asked first, each at most once a day - the
 * import's **exact** pairing rules are asked again, a player's puzzles in one lookup. Only the rules' "found" (one
 * catalogue puzzle by a rule strong enough to be ticked for a player) counts, and even that **pairs nothing**:
 * pairing makes results public, and that takes the player's own tap. The unlisted puzzle is marked "we think this is
 * …" and its player is told **once per run**, in the app, whatever the number of suggestions - no e-mail.
 *
 * Never suggested again: a puzzle the player answered "not this one" to, here or on a pairing screen of an import.
 */
#[AsMessageHandler]
readonly final class SuggestUnlistedPuzzlePairingsHandler
{
    /** A puzzle is asked about at most once in so many hours */
    private const int HOURS_BETWEEN_LOOKS = 20;

    private const int PUZZLES_PER_LOOKUP = 200;

    public function __construct(
        private UnlistedPuzzleRepository $unlistedRepository,
        private UnlistedPuzzleMatcher $matcher,
        private ImportPuzzleDecisionRepository $decisionRepository,
        private NotificationRepository $notificationRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{looked: int, suggested: int, playersTold: int}
     */
    public function __invoke(SuggestUnlistedPuzzlePairings $message): array
    {
        $now = $this->clock->now();
        $unlistedPuzzles = $this->unlistedRepository->nextToMatch($now->modify(sprintf('-%d hours', self::HOURS_BETWEEN_LOOKS)), max(1, $message->limit));

        /** @var array<string, list<UnlistedPuzzle>> $byPlayer */
        $byPlayer = [];
        /** @var array<string, Player> $players */
        $players = [];

        foreach ($unlistedPuzzles as $unlistedPuzzle) {
            $playerId = $unlistedPuzzle->player->id->toString();
            $byPlayer[$playerId][] = $unlistedPuzzle;
            $players[$playerId] = $unlistedPuzzle->player;
        }

        $suggested = 0;
        $playersTold = 0;

        foreach ($byPlayer as $playerId => $ofPlayer) {
            $new = 0;

            foreach (array_chunk($ofPlayer, self::PUZZLES_PER_LOOKUP) as $chunk) {
                $said = $this->matcher->exact($chunk, $playerId, $now);
                $remembered = $this->decisionRepository->ofPlayerByKeys(
                    $players[$playerId],
                    array_map(static fn (UnlistedPuzzle $unlistedPuzzle): string => $unlistedPuzzle->puzzleKey, $chunk),
                );

                foreach ($chunk as $unlistedPuzzle) {
                    $found = $said[$unlistedPuzzle->id->toString()] ?? null;
                    $decision = $remembered[$unlistedPuzzle->puzzleKey] ?? null;

                    if (
                        $found === null
                        || $found['tier'] !== ImportPuzzleTier::Found
                        || $found['puzzleId'] === null
                        // "Not this puzzle", said on a pairing screen of an import
                        || ($decision !== null && $decision->choice === ImportPuzzleChoice::NotThisPuzzle && $decision->puzzle?->id->toString() === $found['puzzleId'])
                    ) {
                        $unlistedPuzzle->nothingFound($now);

                        continue;
                    }

                    $new += $unlistedPuzzle->suggested(Uuid::fromString($found['puzzleId']), $now) ? 1 : 0;
                }
            }

            if ($new > 0) {
                $suggested += $new;
                $playersTold++;
                $this->notificationRepository->save(new Notification(Uuid::uuid7(), $players[$playerId], NotificationType::UnlistedPuzzlesFound, $now));
            }
        }

        return ['looked' => count($unlistedPuzzles), 'suggested' => $suggested, 'playersTold' => $playersTold];
    }
}
