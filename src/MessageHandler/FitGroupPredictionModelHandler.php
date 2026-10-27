<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\FitGroupPredictionModel;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Group\GroupModelRecalculator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class FitGroupPredictionModelHandler
{
    public function __construct(
        private GroupModelRecalculator $recalculator,
        private ClockInterface $clock,
        #[Autowire(param: 'predictionGroupRows')]
        private bool $predictionGroupRows,
    ) {
    }

    /**
     * @return array<string, int|float|string>
     */
    public function __invoke(FitGroupPredictionModel $message): array
    {
        $writeRows = $this->predictionGroupRows || $message->writeRows;

        return ['rows' => $writeRows ? 'written' : 'off (PREDICTION_GROUP_ROWS=0)'] + $this->recalculator->run($this->clock->now(), $writeRows, $message->recompute);
    }
}
