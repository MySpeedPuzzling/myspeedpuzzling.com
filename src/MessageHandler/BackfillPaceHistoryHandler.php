<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\BackfillPaceHistory;
use SpeedPuzzling\Web\Results\PaceHistorySyncResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerPaceHistoryWriter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class BackfillPaceHistoryHandler
{
    private const int PLAYERS_PER_BATCH = 300;
    private const string BEFORE_EVERY_UUID = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private PlayerPaceHistoryWriter $paceHistoryWriter,
    ) {
    }

    public function __invoke(BackfillPaceHistory $message): PaceHistorySyncResult
    {
        // The whole walk is the handler's transaction: a batch run waits for it instead of writing the same pairs
        $this->paceHistoryWriter->lockUntilCommit();

        if ($message->playerId !== null) {
            return $this->paceHistoryWriter->sync([$message->playerId]);
        }

        $result = new PaceHistorySyncResult();
        $after = self::BEFORE_EVERY_UUID;

        while (($playerIds = $this->paceHistoryWriter->playerIdsAfter($after, self::PLAYERS_PER_BATCH)) !== []) {
            $result->add($this->paceHistoryWriter->sync($playerIds));
            $after = $playerIds[array_key_last($playerIds)];
        }

        $this->paceHistoryWriter->markBuilt();

        return $result;
    }
}
