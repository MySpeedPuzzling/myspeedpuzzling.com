<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\PuzzleDifficultyEstimateModel;
use SpeedPuzzling\Web\Message\RecordPuzzleDifficultyEstimateModel;
use SpeedPuzzling\Web\Repository\PuzzleDifficultyEstimateModelRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecordPuzzleDifficultyEstimateModelHandler
{
    public function __construct(
        private PuzzleDifficultyEstimateModelRepository $modelRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordPuzzleDifficultyEstimateModel $message): void
    {
        $now = $this->clock->now();
        $input = $message->model;

        $this->modelRepository->save(new PuzzleDifficultyEstimateModel(
            id: $message->recordId,
            model: $input->model,
            modelVersion: $input->modelVersion,
            trainingSize: $input->trainingSize,
            holdoutSpearman: $input->holdoutSpearman,
            evaluation: $input->evaluation,
            createdAt: $input->createdAt ?? $now,
            receivedAt: $now,
        ));
    }
}
