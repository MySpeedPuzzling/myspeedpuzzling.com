<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\ImportCompetitionParticipants;
use SpeedPuzzling\Web\Results\ParticipantImportResult;
use SpeedPuzzling\Web\Services\CompetitionParticipantImporter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ImportCompetitionParticipantsHandler
{
    public function __construct(
        private CompetitionParticipantImporter $importer,
    ) {
    }

    public function __invoke(ImportCompetitionParticipants $message): ParticipantImportResult
    {
        return $this->importer->import($message->competitionId, $message->filePath);
    }
}
