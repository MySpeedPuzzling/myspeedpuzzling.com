<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

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
 * Time predictions of the studio entrants on screen (analytics-studio.md §2.8 as amended by R3: no round-wide warm) -
 * computed synchronously only for the selected entrant and the compare set, with the one prediction math
 * (GetPlayerPredictions::forPuzzles(), the shown model of PREDICTION_SHOWN_MODEL - the puzzle page's numbers), never
 * re-implemented. Cached in `analytics_studio_cache` per player × visible puzzle set × shown model × model version for
 * 15 minutes through CacheInterface::get() (one computation however many commentators open the same entrant).
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

        $byPuzzle = $this->forPlayer($playerId, array_values(array_map(static fn (VisibleRoundPuzzle $puzzle): string => strtolower($puzzle->puzzleId), $visible)));
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
        $key = 'prediction.' . $playerId . '.' . sha1(implode(',', $puzzleIds)) . '.' . $this->shownModel->value . '.' . TimePredictionCalculator::MODEL_VERSION;

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
