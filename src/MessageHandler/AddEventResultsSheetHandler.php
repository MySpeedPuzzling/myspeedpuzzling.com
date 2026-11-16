<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Exceptions\ActingPlayerNotOnOrganizationTeam;
use SpeedPuzzling\Web\Exceptions\EventResultsSheetAlreadyRegistered;
use SpeedPuzzling\Web\Exceptions\UnknownResultsFormat;
use SpeedPuzzling\Web\Message\AddEventResultsSheet;
use SpeedPuzzling\Web\Repository\EventResultsSheetRepository;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\ResultsFormats;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddEventResultsSheetHandler
{
    public function __construct(
        private EventResultsSheetRepository $sheetRepository,
        private OrganizationRepository $organizationRepository,
        private PlayerRepository $playerRepository,
        private ResultsFormats $formats,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ActingPlayerNotOnOrganizationTeam
     * @throws EventResultsSheetAlreadyRegistered
     * @throws UnknownResultsFormat
     */
    public function __invoke(AddEventResultsSheet $message): void
    {
        $format = $this->formats->get($message->format)->key();
        $organization = $this->organizationRepository->get($message->organizationId);
        $player = $this->playerRepository->get($message->actingPlayerId);

        if ($organization->isOnTeam($player) === false) {
            throw new ActingPlayerNotOnOrganizationTeam();
        }

        if ($this->sheetRepository->findBySpreadsheetId($message->spreadsheetId) !== null) {
            throw new EventResultsSheetAlreadyRegistered();
        }

        $this->sheetRepository->save(new EventResultsSheet(
            id: $message->sheetId,
            spreadsheetId: $message->spreadsheetId,
            organization: $organization,
            actingPlayer: $player,
            timezone: $message->timezone,
            countryCode: strtolower($message->countryCode),
            addedAt: $this->clock->now(),
            format: $format,
        ));
    }
}
