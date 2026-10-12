<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\Badge;
use SpeedPuzzling\Web\Message\DateAchievementsFromHistory;
use SpeedPuzzling\Web\Repository\BadgeRepository;
use SpeedPuzzling\Web\Results\AchievementDatingOutcome;
use SpeedPuzzling\Web\Services\Badges\AchievementFirstMetDates;
use SpeedPuzzling\Web\Services\Badges\LaunchStampWindow;
use SpeedPuzzling\Web\Services\Progression\PlayerProgressionLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Real dates for the tiers the launch backfill stamped (docs/features/badges.md "Real dates"): each tier still inside
 * the backfill's window (HeldAtLaunch) gets the moment the player's records first met it (AchievementFirstMetDates) -
 * only when the replay of the history ends at the player's current records, and only ever an earlier date. A tier the
 * replay cannot date keeps its stamp and stays "held at launch". Nothing else changes: the tiers, their points and
 * their reveal state stay as they are.
 */
#[AsMessageHandler]
readonly final class DateAchievementsFromHistoryHandler
{
    public function __construct(
        private BadgeRepository $badgeRepository,
        private AchievementFirstMetDates $achievementFirstMetDates,
        private LaunchStampWindow $launchStampWindow,
        private PlayerProgressionLock $playerProgressionLock,
    ) {
    }

    public function __invoke(DateAchievementsFromHistory $message): AchievementDatingOutcome
    {
        $window = $this->launchStampWindow->current();

        if ($window->launchAt === null) {
            return new AchievementDatingOutcome();
        }

        // An evaluation of the same player at once would add or remove the tiers being dated
        $this->playerProgressionLock->lock([$message->playerId]);

        $stamped = array_values(array_filter(
            $this->badgeRepository->tieredOf($message->playerId),
            static fn (Badge $badge): bool => $window->holds($badge->earnedAt),
        ));

        if ($stamped === []) {
            return new AchievementDatingOutcome();
        }

        $firstMet = $this->achievementFirstMetDates->forPlayer($message->playerId);

        if ($firstMet === null) {
            return new AchievementDatingOutcome(undated: count($stamped), replayMatched: false);
        }

        $dated = 0;

        foreach ($stamped as $badge) {
            $date = $badge->tier === null ? null : ($firstMet[$badge->type->value][$badge->tier] ?? null);

            if ($date === null || $date >= $badge->earnedAt) {
                continue;
            }

            if ($message->dryRun === false) {
                $badge->datedFromHistory($date);
            }

            $dated++;
        }

        return new AchievementDatingOutcome(dated: $dated, undated: count($stamped) - $dated);
    }
}
