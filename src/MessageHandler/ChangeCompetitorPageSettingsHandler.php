<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Message\ChangeCompetitorPageSettings;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The settings arrive normalised (CompetitorPageSettings: pages off ⇒ time proposals off).
 */
#[AsMessageHandler]
readonly final class ChangeCompetitorPageSettingsHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
    ) {
    }

    /**
     * @throws CompetitionNotFound
     */
    public function __invoke(ChangeCompetitorPageSettings $message): void
    {
        $this->competitionRepository
            ->get($message->competitionId)
            ->changeCompetitorPageSettings($message->settings);
    }
}
