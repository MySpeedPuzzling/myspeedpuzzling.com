<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportSettings;
use SpeedPuzzling\Web\Message\ChangeImportAddingPaused;
use SpeedPuzzling\Web\Repository\DataImportSettingsRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The safety switch (docs/features/data-import/adding.md "The switch"): who flipped it, when and why stay on the
 * settings row; every flip is a warning in the log - a person stopped (or released) what players add.
 */
#[AsMessageHandler]
readonly final class ChangeImportAddingPausedHandler
{
    public function __construct(
        private DataImportSettingsRepository $settingsRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ChangeImportAddingPaused $message): void
    {
        // Two admins at once: the settings row is made once
        $this->idLock->lockUntilCommit(Uuid::uuid5(Uuid::NAMESPACE_OID, 'data-import-settings-' . DataImportSettings::ID));

        $reason = $message->reason === null || trim($message->reason) === '' ? null : mb_substr(trim($message->reason), 0, 1000);
        $this->settingsRepository->getOrCreate()->changeAddingPaused(
            $message->paused,
            Uuid::fromString($message->adminPlayerId),
            $reason,
            $this->clock->now(),
        );

        $this->logger->warning($message->paused ? 'Adding of spreadsheet imports was paused by an admin' : 'Adding of spreadsheet imports was switched on again by an admin', [
            'admin_player_id' => $message->adminPlayerId,
            'reason' => $reason,
        ]);
    }
}
