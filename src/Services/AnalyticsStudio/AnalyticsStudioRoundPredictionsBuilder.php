<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

use Psr\Cache\CacheItemPoolInterface;
use SpeedPuzzling\Web\Exceptions\AnalyticsStudioNotFound;
use SpeedPuzzling\Web\Message\WarmAnalyticsStudioPredictions;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioEntrants;
use SpeedPuzzling\Web\Results\AnalyticsStudioContext;
use SpeedPuzzling\Web\Results\AnalyticsStudioRoundPredictionsAnswer;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundClock;
use stdClass;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The analytics studio's `round-predictions` answer (analytics-studio.md R28-R33): the time predictions of every entrant
 * of a solo round, read from the per-player entries the entrant and compare answers share (one multi-get, never a
 * computation) - so the column always equals the detail. Statements: the round and its visible puzzles (2), the
 * entrants (3); a pair/team round or one without a visible puzzle stops after 2 with `status: none` (no pair/team
 * prediction - D12; nothing before a reveal - D7/R1).
 *
 * `ready` = every entrant's answer is there; `warming` = some are not cached yet (absent from their entry's `members`)
 * - then the answer carries the warm for the controller to send, unless one was sent for the same round and visible
 * puzzles within WARMING_MARKER_SECONDS (markWarming()). Two web workers racing may both send one: harmless, the second
 * finds everything cached.
 */
readonly final class AnalyticsStudioRoundPredictionsBuilder
{
    public const int WARMING_MARKER_SECONDS = 60;

    public function __construct(
        private AnalyticsStudioContextLoader $contextLoader,
        private GetAnalyticsStudioEntrants $getAnalyticsStudioEntrants,
        private AnalyticsStudioPredictions $predictions,
        #[Autowire(service: 'analytics_studio_cache')]
        private CacheItemPoolInterface $analyticsStudioPool,
    ) {
    }

    /**
     * @throws AnalyticsStudioNotFound
     */
    public function build(string $roundId): AnalyticsStudioRoundPredictionsAnswer
    {
        $context = $this->contextLoader->load($roundId);

        if (self::predictable($context) === false) {
            return new AnalyticsStudioRoundPredictionsAnswer(self::json($context, 'none', []), null);
        }

        $entrants = $this->getAnalyticsStudioEntrants->forRound($context->round);
        $members = [];

        foreach ($entrants as $entrant) {
            foreach ($entrant->members as $member) {
                $members[] = $member;
            }
        }

        $cached = $this->predictions->cachedForMembers($members, $context->visible);
        $byEntry = [];
        $missing = false;

        foreach ($entrants as $entrant) {
            $answers = [];

            foreach ($entrant->members as $member) {
                if (array_key_exists($member->participantId, $cached) === false) {
                    $missing = true;

                    continue;
                }

                $answers[] = $cached[$member->participantId];
            }

            $byEntry[$entrant->key()] = ['members' => $answers];
        }

        if ($missing === false) {
            return new AnalyticsStudioRoundPredictionsAnswer(self::json($context, 'ready', $byEntry), null);
        }

        $warm = $this->analyticsStudioPool->hasItem(self::warmingKey($context->round->id, $context->visibleHash))
            ? null
            : new WarmAnalyticsStudioPredictions($context->round->id, $context->visibleHash, RoundClock::milliseconds($context->now));

        return new AnalyticsStudioRoundPredictionsAnswer(self::json($context, 'warming', $byEntry), $warm);
    }

    /**
     * After the warm was sent, and again by each turn of it that queues the next one: no other warm for the same round
     * and visible puzzles while this chain runs (a turn ends within WarmAnalyticsStudioPredictionsHandler::SECONDS_PER_TURN,
     * far inside the minute).
     */
    public function markWarming(WarmAnalyticsStudioPredictions $warm): void
    {
        $item = $this->analyticsStudioPool->getItem(self::warmingKey($warm->roundId, $warm->visibleHash));
        $item->set(1);
        $item->expiresAfter(self::WARMING_MARKER_SECONDS);
        $this->analyticsStudioPool->save($item);
    }

    /**
     * The warm's chain has ended (everything computed, nothing to do, or its last turn): the next answer that misses
     * somebody may send a new one at once.
     */
    public function warmingEnded(WarmAnalyticsStudioPredictions $warm): void
    {
        $this->analyticsStudioPool->deleteItem(self::warmingKey($warm->roundId, $warm->visibleHash));
    }

    /**
     * Whether the round's entrants get predictions now: a solo round (D12) with a visible puzzle (D7/R1).
     */
    public static function predictable(AnalyticsStudioContext $context): bool
    {
        return $context->round->category === RoundCategory::Solo && $context->visible !== [];
    }

    private static function warmingKey(string $roundId, string $visibleHash): string
    {
        return 'roundpredictions.warming.' . strtolower($roundId) . '.' . $visibleHash;
    }

    /**
     * @param array<string, array{members: list<mixed>}> $byEntry
     * @return array<string, mixed>
     */
    private static function json(AnalyticsStudioContext $context, string $status, array $byEntry): array
    {
        return [
            'visibleHash' => $context->visibleHash,
            'status' => $status,
            'entrants' => $byEntry === [] ? new stdClass() : $byEntry,
        ];
    }
}
