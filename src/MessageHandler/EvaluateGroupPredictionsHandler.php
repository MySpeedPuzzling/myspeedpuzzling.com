<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\EvaluateGroupPredictions;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Group\GroupPredictionEvaluator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Group\GroupResultSetLoader;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Value\GroupModelParameters;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Read only: loads every pair/team result, fits the model before the cut and scores everything after it.
 */
#[AsMessageHandler]
readonly final class EvaluateGroupPredictionsHandler
{
    public function __construct(
        private GroupResultSetLoader $loader,
        private PredictionModelParametersProvider $parametersProvider,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{parameters: GroupModelParameters, groups: array<string, array<string, null|int|float>>, reliability: array<string, array<string, null|int|float>>, seconds: array{load: float, fit_and_score: float}}
     */
    public function __invoke(EvaluateGroupPredictions $message): array
    {
        $startedAt = microtime(true);
        $set = $this->loader->load($this->clock->now());
        $loaded = microtime(true);

        $result = (new GroupPredictionEvaluator())->evaluate(
            $set,
            (float) $message->fitBefore->getTimestamp(),
            $message->until !== null ? (float) $message->until->getTimestamp() : null,
            $this->parametersProvider->current()->tau,
            $message->off,
        );

        return $result + ['seconds' => ['load' => round($loaded - $startedAt, 1), 'fit_and_score' => round(microtime(true) - $loaded, 1)]];
    }
}
