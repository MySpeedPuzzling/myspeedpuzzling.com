<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells the open participants spreadsheets of an event that its participants, round places, pairs/teams or rounds
 * changed - a private Mercure update on `/competition-participants/{competitionId}` (topic()), followed only by the sheet
 * page with the subscriber token its state carries (OfficialResultsSubscription::forParticipantsSheet()), never through
 * the subscribe cookie. docs/features/competitions-management/participants-spreadsheet.md.
 *
 * Payload: {"type": "participants_sheet.changed", "competitionId": "…", "version": "<GetParticipantsSheetVersion>"} -
 * a page whose known version differs fetches the state again. Results, table numbers and qualified marks travel on the
 * rounds' own topics (OfficialResultsLiveUpdates).
 *
 * Called by the controllers after the dispatch returned, i.e. after the commit. A Mercure failure never fails the
 * write: it is logged (warning) and the pages catch up on their next version check.
 */
final readonly class ParticipantsSheetLiveUpdates
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public static function topic(string $competitionId): string
    {
        return '/competition-participants/' . strtolower($competitionId);
    }

    public function changed(string $competitionId, string $version): void
    {
        try {
            $this->hub->publish(new Update(self::topic($competitionId), json_encode([
                'type' => 'participants_sheet.changed',
                'competitionId' => strtolower($competitionId),
                'version' => $version,
            ], JSON_THROW_ON_ERROR), private: true));
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not publish a participants sheet update', [
                'competition_id' => $competitionId,
                'exception' => $exception,
            ]);
        }
    }
}
