<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Results\AnalyticsStudioMember;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Results\VisibleRoundPuzzle;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\ShownPredictionModel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Time predictions of the studio entrants (analytics-studio.md §2.8 as amended by R3, R29, R30) - computed synchronously
 * for the selected entrant and the compare set, and for the whole round by WarmAnalyticsStudioPredictionsHandler in the
 * background (the same forMember(), the same keys), with the one prediction math
 * (GetPlayerPredictions::forPuzzles(), the shown model of PREDICTION_SHOWN_MODEL - the puzzle page's numbers), never
 * re-implemented. Cached in `analytics_studio_cache` per player × visible puzzle set × shown model × model version for
 * 15 minutes through CacheInterface::get() (one computation however many commentators open the same entrant). The
 * round's column reads those entries only (cachedForMembers(): one multi-get, never a computation), so the column, the
 * detail and the compare table can never disagree.
 *
 * Only an analysable member (linked, public profile) who did not opt out of time predictions gets one, only for visible
 * puzzles - nothing is computed for a hidden puzzle, so neither the cache nor the timing tells anything about it.
 * No pair/team prediction (D12): a member of a pair/team gets their solo one (labelled solo by the page).
 *
 * @phpstan-type Prediction array{seconds: int, low50: int, high50: int, low80: null|int, high80: null|int, method: string}
 */
final class AnalyticsStudioPredictions implements ResetInterface
{
    public const int TTL_SECONDS = 900;

    /** @var array<string, array<string, Prediction>> cache key => puzzle id => prediction, for this request */
    private array $memo = [];

    private readonly ShownPredictionModel $shownModel;

    public function __construct(
        private readonly GetPlayerPredictions $getPlayerPredictions,
        private readonly CacheInterface $analyticsStudioCache,
        // The same pool read as PSR-6 - its multi-get (cachedForMembers())
        #[Autowire(service: 'analytics_studio_cache')]
        private readonly CacheItemPoolInterface $analyticsStudioPool,
        #[Autowire(param: 'predictionShownModel')]
        string $shownModel = 'v3',
    ) {
        $this->shownModel = ShownPredictionModel::fromSetting($shownModel);
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    /**
     * A member as the entrant and predictions answers carry them: `{}` for a member without public history (unlinked or
     * private - indistinguishable), else whether they opted out and their prediction per visible slot.
     *
     * @param array<string, VisibleRoundPuzzle> $visible
     * @return \stdClass|array{predictionsOptedOut: bool, prediction: null|\stdClass|array<string, Prediction>}
     */
    public function memberJson(AnalyticsStudioMember $member, array $visible): \stdClass|array
    {
        if ($member->analysable() === false) {
            return new \stdClass();
        }

        $prediction = $this->forMember($member, $visible);

        return [
            'predictionsOptedOut' => $member->timePredictionsOptedOut,
            'prediction' => $prediction === null ? null : ($prediction === [] ? new \stdClass() : $prediction),
        ];
    }

    /**
     * The members' answers as memberJson() gives them, from what is cached only - one multi-get of the pool, never a
     * computation, 0 statements. A member without public history is `{}` and one who opted out has no numbers (neither
     * needs the cache); a member whose prediction is not cached yet is absent.
     *
     * @param list<AnalyticsStudioMember> $members
     * @param array<string, VisibleRoundPuzzle> $visible
     * @return array<string, \stdClass|array{predictionsOptedOut: bool, prediction: null|\stdClass|array<string, Prediction>}> participant id => member
     */
    public function cachedForMembers(array $members, array $visible): array
    {
        $puzzleIds = self::puzzleIdsOf($visible);
        $answers = [];
        $keys = [];

        foreach ($members as $member) {
            $playerId = $member->analysablePlayerId();

            if ($playerId === null) {
                $answers[$member->participantId] = new \stdClass();
            } elseif ($member->timePredictionsOptedOut) {
                $answers[$member->participantId] = ['predictionsOptedOut' => true, 'prediction' => null];
            } elseif ($visible === []) {
                $answers[$member->participantId] = ['predictionsOptedOut' => false, 'prediction' => new \stdClass()];
            } else {
                $keys[self::cacheKey($playerId, $puzzleIds, $this->shownModel)][] = $member->participantId;
            }
        }

        if ($keys === []) {
            return $answers;
        }

        // PSR-6 types getItems() as a bare iterable - its keys and items are checked, not assumed
        foreach ($this->analyticsStudioPool->getItems(array_keys($keys)) as $key => $item) {
            if (is_string($key) === false || isset($keys[$key]) === false || $item instanceof CacheItemInterface === false || $item->isHit() === false) {
                continue;
            }

            $value = $item->get();

            if (is_array($value) === false) {
                continue;
            }

            /** @var array<string, Prediction> $value */
            $bySlot = self::bySlot($value, $visible);

            foreach ($keys[$key] as $participantId) {
                $answers[$participantId] = ['predictionsOptedOut' => false, 'prediction' => $bySlot === [] ? new \stdClass() : $bySlot];
            }
        }

        return $answers;
    }

    /**
     * "No prediction" for a member whose computation failed, kept like a computed one (TTL_SECONDS): the round's warm
     * goes on with the others and does not try that player again until the entry expires - the column shows them
     * without a value, the detail likewise.
     *
     * @param array<string, VisibleRoundPuzzle> $visible
     */
    public function rememberNoPrediction(AnalyticsStudioMember $member, array $visible): void
    {
        $playerId = $member->analysablePlayerId();

        if ($playerId === null || $visible === []) {
            return;
        }

        $item = $this->analyticsStudioPool->getItem(self::cacheKey($playerId, self::puzzleIdsOf($visible), $this->shownModel));
        $item->set([]);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->analyticsStudioPool->save($item);
    }

    /**
     * The cache key of a player's predictions for a set of puzzles (the visible ones) under the shown model - shared by
     * the computation (forPlayer()) and the round's multi-get (cachedForMembers()).
     *
     * @param list<string> $puzzleIds
     */
    public static function cacheKey(string $playerId, array $puzzleIds, ShownPredictionModel $shownModel): string
    {
        $puzzleIds = array_values(array_unique(array_map('strtolower', $puzzleIds)));
        sort($puzzleIds);

        return 'prediction.' . $playerId . '.' . sha1(implode(',', $puzzleIds)) . '.' . $shownModel->value . '.' . TimePredictionCalculator::MODEL_VERSION;
    }

    /**
     * @param array<string, VisibleRoundPuzzle> $visible slot key => visible puzzle
     * @return null|array<string, Prediction> slot key => prediction (a slot the model has none for is absent); null when
     *     the member gets none (not analysable or opted out)
     */
    public function forMember(AnalyticsStudioMember $member, array $visible): null|array
    {
        $playerId = $member->analysablePlayerId();

        if ($playerId === null || $member->timePredictionsOptedOut) {
            return null;
        }

        if ($visible === []) {
            return [];
        }

        return self::bySlot($this->forPlayer($playerId, self::puzzleIdsOf($visible)), $visible);
    }

    /**
     * @param array<string, VisibleRoundPuzzle> $visible
     * @return list<string>
     */
    private static function puzzleIdsOf(array $visible): array
    {
        return array_values(array_map(static fn (VisibleRoundPuzzle $puzzle): string => strtolower($puzzle->puzzleId), $visible));
    }

    /**
     * @param array<string, Prediction> $byPuzzle puzzle id => prediction
     * @param array<string, VisibleRoundPuzzle> $visible
     * @return array<string, Prediction> slot key => prediction
     */
    private static function bySlot(array $byPuzzle, array $visible): array
    {
        $bySlot = [];

        foreach ($visible as $slotKey => $puzzle) {
            $prediction = $byPuzzle[strtolower($puzzle->puzzleId)] ?? null;

            if ($prediction !== null) {
                $bySlot[$slotKey] = $prediction;
            }
        }

        return $bySlot;
    }

    /**
     * @param list<string> $puzzleIds
     * @return array<string, Prediction>
     */
    private function forPlayer(string $playerId, array $puzzleIds): array
    {
        $puzzleIds = array_values(array_unique($puzzleIds));
        sort($puzzleIds);
        $key = self::cacheKey($playerId, $puzzleIds, $this->shownModel);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        /** @var array<string, Prediction> $predictions */
        $predictions = $this->analyticsStudioCache->get($key, function (ItemInterface $item) use ($playerId, $puzzleIds): array {
            $item->expiresAfter(self::TTL_SECONDS);
            $result = [];

            foreach ($this->getPlayerPredictions->forPuzzles($playerId, $puzzleIds) as $puzzleId => $prediction) {
                $result[strtolower((string) $puzzleId)] = self::toArray($prediction);
            }

            return $result;
        });

        return $this->memo[$key] = $predictions;
    }

    /**
     * @return Prediction
     */
    private static function toArray(TimePredictionResult $prediction): array
    {
        return [
            'seconds' => $prediction->predictedSeconds,
            'low50' => $prediction->rangeLowSeconds,
            'high50' => $prediction->rangeHighSeconds,
            'low80' => $prediction->range80LowSeconds,
            'high80' => $prediction->range80HighSeconds,
            // personal = from the player's own attempts on the puzzle, general = from their pace and its difficulty
            'method' => $prediction->isPersonalized ? 'personal' : 'general',
        ];
    }
}
